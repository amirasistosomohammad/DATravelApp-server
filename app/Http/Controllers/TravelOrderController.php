<?php

namespace App\Http\Controllers;

use App\Models\Director;
use App\Models\Personnel;
use App\Models\TravelOrder;
use App\Models\TravelOrderApproval;
use App\Models\TravelOrderAttachment;
use Illuminate\Http\Request;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;

class TravelOrderController extends Controller
{
    private const TO_EXCEL_TEMPLATE = 'templates/TRAVEL ORDER FOR CAPSTONE.xlsx';
    private const TO_EXCEL_SHEET_COS = 'TO for COS';

    /**
     * Ensure the authenticated user is Personnel.
     */
    protected function ensurePersonnel(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof Personnel) {
            return response()->json([
                'success' => false,
                'message' => 'Only personnel can manage travel orders.',
            ], 403);
        }

        return null;
    }

    /**
     * List travel orders for the authenticated personnel.
     */
    public function index(Request $request)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        $query = TravelOrder::query()
            ->where('personnel_id', $personnel->id)
            ->with(['attachments', 'approvals.director'])
            ->orderByDesc('updated_at');

        $status = $request->query('status');
        if ($status && in_array($status, ['draft', 'pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $perPage = (int) $request->query('per_page', 10);
        if ($perPage <= 0) {
            $perPage = 10;
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem() ?? 0,
                    'to' => $paginator->lastItem() ?? 0,
                    'per_page' => $paginator->perPage(),
                ],
            ],
        ]);
    }

    /**
     * Show a single travel order (own only).
     */
    public function show(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        if ((int) $travelOrder->personnel_id !== (int) $personnel->id) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found.',
            ], 404);
        }

        $travelOrder->load(['attachments', 'approvals.director', 'personnel']);

        return response()->json([
            'success' => true,
            'data' => $travelOrder,
        ]);
    }

    /**
     * Create a new travel order (draft) with optional attachments.
     */
    public function store(Request $request)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'travel_purpose' => ['required', 'string', 'max:500'],
            'destination' => ['required', 'string', 'max:255'],
            'official_station' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'objectives' => ['nullable', 'string'],
            'per_diems_expenses' => ['nullable', 'numeric', 'min:0'],
            'per_diems_note' => ['nullable', 'string', 'max:255'],
            'assistant_or_laborers_allowed' => ['nullable', 'string', 'max:255'],
            'appropriation' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['nullable', 'file', 'max:20480'], // 20 MB in KB
        ], [
            'attachments.*.max' => 'Each attachment must not exceed 20 MB.',
        ]);

        /** @var Personnel $personnel */
        $personnel = $request->user();

        $validated['personnel_id'] = $personnel->id;
        $validated['status'] = 'draft';

        $travelOrder = TravelOrder::create($validated);

        $this->storeAttachments($request, $travelOrder);

        $travelOrder->load('attachments');

        return response()->json([
            'success' => true,
            'message' => 'Travel order created successfully.',
            'data' => $travelOrder,
        ], 201);
    }

    /**
     * Update a travel order (draft only).
     */
    public function update(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        if ((int) $travelOrder->personnel_id !== (int) $personnel->id) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found.',
            ], 404);
        }

        if ($travelOrder->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft travel orders can be updated.',
            ], 422);
        }

        $validated = $request->validate([
            'travel_purpose' => ['sometimes', 'string', 'max:500'],
            'destination' => ['sometimes', 'string', 'max:255'],
            'official_station' => ['nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'objectives' => ['nullable', 'string'],
            'per_diems_expenses' => ['nullable', 'numeric', 'min:0'],
            'per_diems_note' => ['nullable', 'string', 'max:255'],
            'assistant_or_laborers_allowed' => ['nullable', 'string', 'max:255'],
            'appropriation' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['nullable', 'file', 'max:20480'], // 20 MB in KB
            // Map of existing attachment IDs to updated types (e.g. existing_attachment_types[12]=memorandum)
            'existing_attachment_types' => ['nullable', 'array'],
        ], [
            'attachments.*.max' => 'Each attachment must not exceed 20 MB.',
        ]);

        $travelOrder->update($validated);

        // Handle attachment removals (client sends delete_ids)
        $deleteIds = $request->input('delete_attachment_ids', []);
        if (is_array($deleteIds) && !empty($deleteIds)) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, TravelOrderAttachment> $toDelete */
            $toDelete = TravelOrderAttachment::query()
                ->where('travel_order_id', $travelOrder->id)
                ->whereIn('id', $deleteIds)
                ->get();
            foreach ($toDelete as $att) {
                Storage::disk('public')->delete($att->file_path);
                $att->delete();
            }
        }

        // Handle updating types for existing attachments
        $allowedTypes = ['itinerary', 'memorandum', 'invitation', 'other'];
        $typeMap = $request->input('existing_attachment_types', []);
        if (is_array($typeMap) && !empty($typeMap)) {
            foreach ($typeMap as $attId => $type) {
                $attId = (int) $attId;
                $type = (string) $type;
                if ($attId <= 0) {
                    continue;
                }
                if (!in_array($type, $allowedTypes, true)) {
                    continue;
                }
                if (is_array($deleteIds) && in_array($attId, $deleteIds, false)) {
                    continue;
                }
                TravelOrderAttachment::query()
                    ->where('travel_order_id', $travelOrder->id)
                    ->where('id', $attId)
                    ->update(['type' => $type]);
            }
        }

        $this->storeAttachments($request, $travelOrder);

        $travelOrder->load('attachments');

        return response()->json([
            'success' => true,
            'message' => 'Travel order updated successfully.',
            'data' => $travelOrder,
        ]);
    }

    /**
     * History: non-draft travel orders for the authenticated personnel.
     * Optional query "status": pending | approved | rejected | all
     */
    public function history(Request $request)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        $query = TravelOrder::query()
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['pending', 'approved', 'rejected'])
            ->with(['attachments', 'approvals.director'])
            ->orderByDesc('updated_at');

        $status = $request->query('status'); // pending | approved | rejected | all
        if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $perPage = (int) $request->query('per_page', 10);
        if ($perPage <= 0) {
            $perPage = 10;
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem() ?? 0,
                    'to' => $paginator->lastItem() ?? 0,
                    'per_page' => $paginator->perPage(),
                ],
            ],
        ]);
    }

    /**
     * Calendar: travel orders overlapping the given date range (for personnel calendar UI).
     * Query params: start (Y-m-d or ISO), end (Y-m-d or ISO), all (true/false) to fetch all orders.
     */
    public function calendar(Request $request)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        $viewAll = $request->query('all') === 'true' || $request->query('all') === '1';

        if ($viewAll) {
            // Fetch all travel orders regardless of date range
            $items = TravelOrder::query()
                ->where('personnel_id', $personnel->id)
                ->orderBy('start_date')
                ->get(['id', 'start_date', 'end_date', 'travel_purpose', 'destination', 'status']);
        } else {
            $startInput = $request->query('start');
            $endInput = $request->query('end');

            try {
                $rangeStart = $startInput ? Carbon::parse($startInput)->startOfDay() : Carbon::now()->startOfMonth();
                $rangeEnd = $endInput ? Carbon::parse($endInput)->endOfDay() : Carbon::now()->endOfMonth()->addMonths(1);
            } catch (\Exception $e) {
                $rangeStart = Carbon::now()->startOfMonth();
                $rangeEnd = Carbon::now()->endOfMonth()->addMonths(1);
            }

            if ($rangeStart->gt($rangeEnd)) {
                [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
            }

            $items = TravelOrder::query()
                ->where('personnel_id', $personnel->id)
                ->where(function ($q) use ($rangeStart, $rangeEnd) {
                    $q->where('start_date', '<=', $rangeEnd->toDateString())
                        ->where('end_date', '>=', $rangeStart->toDateString());
                })
                ->orderBy('start_date')
                ->get(['id', 'start_date', 'end_date', 'travel_purpose', 'destination', 'status']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
            ],
        ]);
    }

    /**
     * Delete a travel order (draft only).
     */
    public function destroy(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        if ((int) $travelOrder->personnel_id !== (int) $personnel->id) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found.',
            ], 404);
        }

        if ($travelOrder->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft travel orders can be deleted.',
            ], 422);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, TravelOrderAttachment> $attachments */
        $attachments = $travelOrder->attachments()->get();
        foreach ($attachments as $att) {
            Storage::disk('public')->delete($att->file_path);
        }
        $travelOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Travel order deleted successfully.',
        ]);
    }

    /**
     * Submit a draft travel order for approval (personnel).
     * Body: recommending_director_id (required), approving_director_id (required).
     */
    public function submit(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        if ((int) $travelOrder->personnel_id !== (int) $personnel->id) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found.',
            ], 404);
        }

        if ($travelOrder->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft travel orders can be submitted.',
            ], 422);
        }

        $validated = $request->validate([
            'recommending_director_id' => ['required', 'integer', 'exists:directors,id', 'different:approving_director_id'],
            'approving_director_id' => ['required', 'integer', 'exists:directors,id'],
        ]);

        $recommendingDirectorId = (int) $validated['recommending_director_id'];
        $approvingDirectorId = (int) $validated['approving_director_id'];

        $approvingDirector = Director::find($approvingDirectorId);
        if (!$approvingDirector || !$approvingDirector->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Selected approving director is not active.',
            ], 422);
        }
        $recDirector = Director::find($recommendingDirectorId);
        if (!$recDirector || !$recDirector->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Selected recommending director is not active.',
            ], 422);
        }

        DB::transaction(function () use ($travelOrder, $recommendingDirectorId, $approvingDirectorId) {
            // Step 1: recommending director (required)
            TravelOrderApproval::create([
                'travel_order_id' => $travelOrder->id,
                'director_id' => $recommendingDirectorId,
                'step_order' => 1,
                'status' => 'pending',
            ]);
            // Step 2: approving director
            TravelOrderApproval::create([
                'travel_order_id' => $travelOrder->id,
                'director_id' => $approvingDirectorId,
                'step_order' => 2,
                'status' => 'pending',
            ]);
            $travelOrder->update([
                'status' => 'pending',
                'submitted_at' => now(),
            ]);
        });

        $travelOrder->load(['attachments', 'approvals.director', 'personnel']);

        return response()->json([
            'success' => true,
            'message' => 'Travel order submitted successfully.',
            'data' => $travelOrder,
        ]);
    }

    /**
     * List active directors for personnel (e.g. for submit dropdown).
     */
    public function availableDirectors(Request $request)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        $directors = Director::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'last_name', 'middle_name', 'position', 'department']);

        return response()->json([
            'success' => true,
            'data' => ['directors' => $directors],
        ]);
    }

    /**
     * Export a travel order as PDF (official form layout).
     * Query: include_ctt=1 to include CERTIFICATION TO TRAVEL section.
     */
    public function exportPdf(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        if ((int) $travelOrder->personnel_id !== (int) $personnel->id) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found.',
            ], 404);
        }

        $includeCtt = (string) $request->query('include_ctt', '0') === '1';

        // Load directors with signature_path so PDF can embed e-signatures
        $travelOrder->load(['personnel', 'attachments', 'approvals.director']);

        if (app()->environment('production') && !extension_loaded('gd')) {
            Log::warning('PDF export: PHP GD extension is not enabled; director signatures will not appear in the PDF.');
        }

        try {
            $pdf = Pdf::loadView('pdf.travel_order', [
                'travelOrder' => $travelOrder,
                'includeCtt' => $includeCtt,
                'generatedAt' => now(),
            ])->setPaper('a4', 'portrait');

            $safeId = $travelOrder->id;
            $filename = "TRAVEL_ORDER_{$safeId}.pdf";

            return $pdf->download($filename);
        } catch (\Exception $e) {
            // Check if it's a GD extension error
            if (str_contains($e->getMessage(), 'GD extension') || str_contains($e->getMessage(), 'gd')) {
                Log::error('PDF generation failed: GD extension not available', [
                    'error' => $e->getMessage(),
                    'php_version' => PHP_VERSION,
                    'gd_loaded' => extension_loaded('gd'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'PDF generation failed: PHP GD extension is required but not enabled. Please enable GD extension in your PHP configuration and restart the server.',
                ], 500);
            }

            // Re-throw other exceptions
            throw $e;
        }
    }

    /**
     * Export a travel order as Excel using the client-provided template.
     * Uses sheet/tab: "TO for COS".
     */
    public function exportExcel(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        if (!extension_loaded('zip')) {
            return response()->json([
                'success' => false,
                'message' => 'Excel export requires the PHP ZIP extension (ext-zip). Please enable it in your PHP configuration and restart the server.',
            ], 500);
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        if ((int) $travelOrder->personnel_id !== (int) $personnel->id) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found.',
            ], 404);
        }

        $travelOrder->load(['personnel', 'approvals.director']);

        $templateAbsolutePath = storage_path('app/' . self::TO_EXCEL_TEMPLATE);
        if (!is_file($templateAbsolutePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Excel template not found on server.',
            ], 500);
        }

        $spreadsheet = IOFactory::load($templateAbsolutePath);
        $sheet = $spreadsheet->getSheetByName(self::TO_EXCEL_SHEET_COS) ?? $spreadsheet->getSheet(1);
        // Do not modify header contents (avoid duplicate "back layer" header text).

        $personnelFullName = trim(collect([
            $travelOrder->personnel?->first_name,
            $travelOrder->personnel?->middle_name,
            $travelOrder->personnel?->last_name,
        ])->filter()->implode(' '));

        $startDate = $this->formatDateForExcel($travelOrder->start_date);
        $endDate = $this->formatDateForExcel($travelOrder->end_date);

        // =========
        // TRAVEL ORDER (upper section)
        // =========
        $this->writeValueOnLine($sheet, ['Name:'], $personnelFullName);
        $this->writeValueOnLine($sheet, ['Position/ Designation:'], (string) ($travelOrder->personnel?->position ?? ''));
        $this->writeValueOnLine($sheet, ['Departure Date:'], $startDate);
        // Write destination and remove underline (both font underline and bottom border)
        $destinationPos = $this->findCellByLabelsExact($sheet, ['Destination:']);
        if ($destinationPos) {
            $destinationCoord = $this->getFirstCellAfterMergedRangeOnRow($sheet, $destinationPos->coord);
            if (!$destinationCoord) {
                $destinationCoord = Coordinate::stringFromColumnIndex($destinationPos->colIndex + 1) . $destinationPos->row;
            }
            $sheet->setCellValue($destinationCoord, (string) ($travelOrder->destination ?? ''));
            $sheet->getStyle($destinationCoord)->getAlignment()->setHorizontal('left')->setVertical('center');

            // Remove underline: both font underline style and bottom border
            $style = $sheet->getStyle($destinationCoord);
            $style->getFont()->setUnderline(Font::UNDERLINE_NONE);
            $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_NONE);

            // Also check if the cell is part of a merged range and clear underline on the entire range
            [$destCol, $destRow] = Coordinate::coordinateFromString($destinationCoord);
            $destColIndex = Coordinate::columnIndexFromString($destCol);
            $destRow = (int) $destRow;

            $mergedRanges = $sheet->getMergeCells();
            foreach ($mergedRanges as $range) {
                [$start, $end] = Coordinate::rangeBoundaries($range);
                $startColIndex = (int) $start[0];
                $startRow = (int) $start[1];
                $endColIndex = (int) $end[0];
                $endRow = (int) $end[1];

                // Check if destination cell is within this merged range
                if (
                    $destRow >= $startRow && $destRow <= $endRow &&
                    $destColIndex >= $startColIndex && $destColIndex <= $endColIndex
                ) {
                    $sheet->getStyle($range)->getFont()->setUnderline(Font::UNDERLINE_NONE);
                    $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_NONE);
                    break;
                }
            }
        }
        // Ensure at least one row of spacing above Purpose (between Destination and Purpose).
        $purposePos = $this->findCellByLabelsExact($sheet, ['Purpose:']);
        if ($destinationPos && $purposePos && $purposePos->row > $destinationPos->row + 1) {
            $spacingHeight = 12.0; // One row of visual spacing
            $gapRows = $purposePos->row - $destinationPos->row - 1;
            if ($gapRows >= 1) {
                // Keep the last row before Purpose at spacing height, collapse any extra rows.
                $sheet->getRowDimension($purposePos->row - 1)->setRowHeight($spacingHeight);
                if ($gapRows > 1) {
                    $minHeight = 3.0;
                    for ($r = $destinationPos->row + 1; $r < $purposePos->row - 1; $r++) {
                        $sheet->getRowDimension($r)->setRowHeight($minHeight);
                    }
                }
            }
        }
        $this->writeValueOnLine($sheet, ['Purpose:'], (string) ($travelOrder->travel_purpose ?? ''), null, 0, true);
        $this->setPurposeRowHeight($sheet, $travelOrder->travel_purpose ?? '', null);
        $this->writeValueOnLine($sheet, ['Objectives:'], (string) ($travelOrder->objectives ?? ''));
        // Keep Objectives on one line: unmerge if template has 2-row merge, disable wrap, set single-line row height.
        $objPos = $this->findCellByLabelsExact($sheet, ['Objectives:']);
        if ($objPos) {
            $objCoord = $this->getFirstCellAfterMergedRangeOnRow($sheet, $objPos->coord);
            if (!$objCoord) {
                $objCoord = Coordinate::stringFromColumnIndex($objPos->colIndex + 1) . $objPos->row;
            }
            // Unmerge the Objectives value cell if it spans multiple rows (template default has 2 rows).
            $this->unmergeCellsContaining($sheet, [$objCoord]);
            $sheet->getStyle($objCoord)->getAlignment()->setWrapText(false);
            $sheet->getRowDimension($objPos->row)->setRowHeight(15);
        }
        // For the four budget/remarks lines we now map directly to specific cells (D30–D33),
        // so we intentionally skip the generic writeValueOnLine calls here to avoid duplicates.

        // For these two fields, we now map directly to the exact cells on the template
        // so they align perfectly on the lines (based on your client's layout).
        $sheet->setCellValue('I17', (string) ($travelOrder->official_station ?? ''));
        $sheet->getStyle('I17')->getAlignment()->setHorizontal('left')->setVertical('center');

        $sheet->setCellValue('I19', $endDate);
        $sheet->getStyle('I19')->getAlignment()->setHorizontal('left')->setVertical('center');

        // Align these four fields to the exact merged ranges on the left block
        // so they sit cleanly on the lines, matching the client's template:
        // D30:K30, D31:K31, D32:K32, D33:K33.
        $sheet->setCellValue('D30', $this->formatPerDiems($travelOrder));
        $sheet->getStyle('D30')->getAlignment()->setHorizontal('left')->setVertical('center');

        $sheet->setCellValue('D31', (string) ($travelOrder->assistant_or_laborers_allowed ?? ''));
        $sheet->getStyle('D31')->getAlignment()->setHorizontal('left')->setVertical('center');

        $sheet->setCellValue('D32', (string) ($travelOrder->appropriation ?? ''));
        $sheet->getStyle('D32')->getAlignment()->setHorizontal('left')->setVertical('center');

        $sheet->setCellValue('D33', (string) ($travelOrder->remarks ?? ''));
        $sheet->getStyle('D33')->getAlignment()->setHorizontal('left')->setVertical('center');
        // Collapse empty rows between sections. Hide the empty row between Objectives and Per Diems (removes "double line").
        $this->collapseEmptyRowsBetween($sheet, ['Objectives:'], ['Per Diems Expenses Allowed:'], null, true);
        $this->collapseEmptyRowsBetween($sheet, ['Remarks / Special Instructions:'], ['RECOMMENDING APPROVAL:']);

        /** @var TravelOrderApproval|null $reco */
        $reco = $travelOrder->approvals->firstWhere('step_order', 1);
        /** @var TravelOrderApproval|null $appr */
        $appr = $travelOrder->approvals->firstWhere('step_order', 2);

        // =========
        // APPROVAL BLOCKS (map directly to template cells for perfect alignment)
        // =========
        if ($reco?->director) {
            $recoName = trim(collect([$reco->director->first_name, $reco->director->middle_name, $reco->director->last_name])->filter()->implode(' '));
            // Template name-line is on row 39 (merged A39:D39). Use bottom alignment so it sits on the underline.
            $sheet->setCellValue('A39', $recoName);
            $sheet->getStyle('A39:D39')->getAlignment()->setHorizontal('center')->setVertical('bottom');
            $sheet->getStyle('A39:D39')->getFont()->setUnderline(Font::UNDERLINE_NONE);
            $sheet->getStyle('A39:D39')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

            if (!empty($reco->director->position)) {
                $sheet->setCellValue('A40', (string) $reco->director->position);
                $sheet->getStyle('A40')->getAlignment()->setHorizontal('center')->setVertical('center');
            }

            // Place recommending director signature directly above the name line (row 39), centered,
            // but only once the recommending step is at least recommended/approved.
            if (in_array($reco->status, ['recommended', 'approved'], true)) {
                $this->placeSignatureAt($sheet, $reco->director->signature_path, 'B36', 220, 70);
            }
        }

        if ($appr?->director) {
            // Merge APPROVED name/position slightly left (G39:K39, G40:K40) so long text is not shrunk.
            $this->unmergeCellsContaining($sheet, ['G39', 'G40', 'H39', 'H40']);
            $sheet->mergeCells('G39:K39');
            $sheet->mergeCells('G40:K40');

            $apprName = trim(collect([$appr->director->first_name, $appr->director->middle_name, $appr->director->last_name])->filter()->implode(' '));
            $sheet->setCellValue('G39', $apprName);
            $sheet->getStyle('G39:K39')->getAlignment()->setHorizontal('center')->setVertical('bottom');
            $sheet->getStyle('G39:K39')->getFont()->setUnderline(Font::UNDERLINE_NONE);
            // Full-width underline: bottom border so the line spans the full cell (G39:K39) width.
            $sheet->getStyle('G39:K39')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

            if (!empty($appr->director->position)) {
                $sheet->setCellValue('G40', (string) $appr->director->position);
                $sheet->getStyle('G40:K40')->getAlignment()->setHorizontal('center')->setVertical('center');
            }

            // Approving director signature directly above right name line, only when fully approved.
            if ($appr->status === 'approved') {
                $this->placeSignatureAt($sheet, $appr->director->signature_path, 'I36', 220, 70);
            }
        }

        // =========
        // CERTIFICATION TO TRAVEL (lower section)
        // =========
        $cttAnchor = $this->findCellByLabelsExact($sheet, ['CERTIFICATION TO TRAVEL']);
        if ($cttAnchor) {
            $startRow = $cttAnchor->row;
            $this->writeValueOnLine($sheet, ['Name:'], $personnelFullName, $startRow);
            $this->writeValueOnLine($sheet, ['Position/ Designation:'], (string) ($travelOrder->personnel?->position ?? ''), $startRow);
            $this->writeValueOnLine($sheet, ['Official Station:'], (string) ($travelOrder->official_station ?? ''), $startRow);
            $this->writeValueOnLine($sheet, ['Departure Date:'], $startDate, $startRow);
            $this->writeValueOnLine($sheet, ['Return Date:'], $endDate, $startRow);
            // Write destination and remove underline (both font underline and bottom border) in Certification section
            $cttDestinationPos = $this->findCellByLabelsExact($sheet, ['Destination:'], $startRow);
            if ($cttDestinationPos) {
                $cttDestinationCoord = $this->getFirstCellAfterMergedRangeOnRow($sheet, $cttDestinationPos->coord);
                if (!$cttDestinationCoord) {
                    $cttDestinationCoord = Coordinate::stringFromColumnIndex($cttDestinationPos->colIndex + 1) . $cttDestinationPos->row;
                }
                $sheet->setCellValue($cttDestinationCoord, (string) ($travelOrder->destination ?? ''));
                $sheet->getStyle($cttDestinationCoord)->getAlignment()->setHorizontal('left')->setVertical('center');

                // Remove underline: both font underline style and bottom border
                $cttStyle = $sheet->getStyle($cttDestinationCoord);
                $cttStyle->getFont()->setUnderline(Font::UNDERLINE_NONE);
                $cttStyle->getBorders()->getBottom()->setBorderStyle(Border::BORDER_NONE);

                // Also check if the cell is part of a merged range and clear underline on the entire range
                [$cttDestCol, $cttDestRow] = Coordinate::coordinateFromString($cttDestinationCoord);
                $cttDestColIndex = Coordinate::columnIndexFromString($cttDestCol);
                $cttDestRow = (int) $cttDestRow;

                $cttMergedRanges = $sheet->getMergeCells();
                foreach ($cttMergedRanges as $cttRange) {
                    [$cttStart, $cttEnd] = Coordinate::rangeBoundaries($cttRange);
                    $cttStartColIndex = (int) $cttStart[0];
                    $cttStartRow = (int) $cttStart[1];
                    $cttEndColIndex = (int) $cttEnd[0];
                    $cttEndRow = (int) $cttEnd[1];

                    // Check if destination cell is within this merged range
                    if (
                        $cttDestRow >= $cttStartRow && $cttDestRow <= $cttEndRow &&
                        $cttDestColIndex >= $cttStartColIndex && $cttDestColIndex <= $cttEndColIndex
                    ) {
                        $sheet->getStyle($cttRange)->getFont()->setUnderline(Font::UNDERLINE_NONE);
                        $sheet->getStyle($cttRange)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_NONE);
                        break;
                    }
                }
            }
            // CERTIFICATION purpose box: template uses a fixed multi-row box (typically B{startRow+8}:K{startRow+10}).
            // Write into that box directly and size the rows so the whole purpose is visible.
            $cttPurposeTopRow = $startRow + 8;
            $cttPurposeBottomRow = $cttPurposeTopRow + 2;
            $cttPurposeTopCoord = 'B' . $cttPurposeTopRow;
            $cttPurposeRange = $cttPurposeTopCoord . ':K' . $cttPurposeBottomRow;
            $this->unmergeCellsContaining($sheet, [$cttPurposeTopCoord]);
            $sheet->mergeCells($cttPurposeRange);
            $sheet->setCellValue($cttPurposeTopCoord, (string) ($travelOrder->travel_purpose ?? ''));
            $sheet->getStyle($cttPurposeRange)->getAlignment()
                ->setWrapText(true)
                ->setHorizontal('left')
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

            $purposeText = (string) ($travelOrder->travel_purpose ?? '');
            $explicitLines = max(1, substr_count($purposeText, "\n") + 1);
            $textLength = mb_strlen($purposeText);
            $charsPerWrappedLine = 55;
            $wrappedLines = max(1, (int) ceil($textLength / $charsPerWrappedLine));
            $lines = max($explicitLines, $wrappedLines) + 1;
            $pointsPerLine = 16;
            $totalHeight = min(409, (float) ($pointsPerLine * $lines));
            $eachRowHeight = max(3.0, $totalHeight / 3.0);
            for ($r = $cttPurposeTopRow; $r <= $cttPurposeBottomRow; $r++) {
                $sheet->getRowDimension($r)->setRowHeight($eachRowHeight);
            }
            $this->writeValueOnLine($sheet, ['Objectives:'], (string) ($travelOrder->objectives ?? ''), $startRow);
            // Keep Objectives on one line: unmerge if template has 2-row merge, disable wrap, set single-line row height.
            $cttObjPos = $this->findCellByLabelsExact($sheet, ['Objectives:'], $startRow);
            if ($cttObjPos) {
                $cttObjCoord = $this->getFirstCellAfterMergedRangeOnRow($sheet, $cttObjPos->coord);
                if (!$cttObjCoord) {
                    $cttObjCoord = Coordinate::stringFromColumnIndex($cttObjPos->colIndex + 1) . $cttObjPos->row;
                }
                // Unmerge the Objectives value cell if it spans multiple rows (template default has 2 rows).
                $this->unmergeCellsContaining($sheet, [$cttObjCoord]);
                $sheet->getStyle($cttObjCoord)->getAlignment()->setWrapText(false);
                $sheet->getRowDimension($cttObjPos->row)->setRowHeight(15);
            }
            $this->writeValueOnLine($sheet, ['Per Diems Expenses Allowed:'], $this->formatPerDiems($travelOrder), $startRow);
            $this->writeValueOnLine($sheet, ['Assistant or Laborers Allowed:'], (string) ($travelOrder->assistant_or_laborers_allowed ?? ''), $startRow);
            $this->writeValueOnLine($sheet, ['Appropriation to which travel should be charged:'], (string) ($travelOrder->appropriation ?? ''), $startRow);
            $this->writeValueOnLine($sheet, ['Remarks / Special Instructions:'], (string) ($travelOrder->remarks ?? ''), $startRow);
        }

        // =========
        // ENDORSED BY / CERTIFIED BY — unmerge footer, then merge only name/position rows (B66:G66, B67:G67, B73:G73, B74:G74) so long text fits; template column widths unchanged.
        // =========
        $this->unmergeCellsContaining($sheet, ['B62', 'B66', 'B67', 'B70', 'B73', 'B74']);
        $sheet->setCellValue('B62', 'Endorsed by:');
        $sheet->setCellValue('B70', 'Certified by:');

        // Merge only name/position cells with columns to the right so full text shows without changing template column widths.
        $sheet->mergeCells('B66:G66');
        $sheet->mergeCells('B67:G67');
        $sheet->mergeCells('B73:G73');
        $sheet->mergeCells('B74:G74');

        if ($reco?->director) {
            $endorsedName = trim(collect([
                $reco->director->first_name,
                $reco->director->middle_name,
                $reco->director->last_name,
            ])->filter()->implode(' '));
            $sheet->setCellValue('B66', $endorsedName);
            $sheet->getStyle('B66:G66')->getAlignment()->setWrapText(true)->setHorizontal('left')->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_BOTTOM);
            $sheet->getStyle('B66')->getAlignment()->setHorizontal('left');
            $sheet->getStyle('B66:G66')->getFont()->setBold(true);
            $sheet->setCellValue('B67', (string) ($reco->director->position ?? ''));
            $sheet->getStyle('B67:G67')->getAlignment()->setWrapText(true)->setHorizontal('left')->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle('B67')->getAlignment()->setHorizontal('left');
            // Offset signature left so it centers over the name (B66),
            // but only show e-signature once recommending step is at least recommended/approved.
            if (in_array($reco->status, ['recommended', 'approved'], true)) {
                $this->placeSignatureAt($sheet, $reco->director->signature_path, 'B63', 220, 70, -55, -5);
            }
        }

        if ($appr?->director) {
            $certName = trim(collect([
                $appr->director->first_name,
                $appr->director->middle_name,
                $appr->director->last_name,
            ])->filter()->implode(' '));
            $sheet->setCellValue('B73', $certName);
            $sheet->getStyle('B73:G73')->getAlignment()->setWrapText(true)->setHorizontal('left')->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_BOTTOM);
            $sheet->getStyle('B73')->getAlignment()->setHorizontal('left');
            $sheet->getStyle('B73:G73')->getFont()->setBold(true);
            $sheet->setCellValue('B74', (string) ($appr->director->position ?? ''));
            $sheet->getStyle('B74:G74')->getAlignment()->setWrapText(true)->setHorizontal('left')->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle('B74')->getAlignment()->setHorizontal('left');
            // Offset signature left so it centers over the name (B73),
            // but only show e-signature once the travel order is fully approved.
            if ($appr->status === 'approved') {
                $this->placeSignatureAt($sheet, $appr->director->signature_path, 'B71', 220, 70, -55, -5);
            }
        }

        $safeId = $travelOrder->id;
        $filename = 'TRAVEL_ORDER_' . $safeId . '_' . now()->format('Ymd_His') . '.xlsx';
        $tmpPath = storage_path('app/tmp/' . Str::uuid()->toString() . '.xlsx');
        if (!is_dir(dirname($tmpPath))) {
            @mkdir(dirname($tmpPath), 0775, true);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $filename)->deleteFileAfterSend(true);
    }

    private function writeValueNextToLabel($sheet, array $labels, string $value, int $colOffset = 1, int $rowOffset = 0): void
    {
        $pos = $this->findCellByLabelsExact($sheet, $labels);
        if (!$pos) return;

        $row = $pos->row;
        $colIndex = $pos->colIndex;
        $targetRow = $row + $rowOffset;
        $targetColIndex = max(1, $colIndex + $colOffset);
        $coord = Coordinate::stringFromColumnIndex($targetColIndex) . $targetRow;
        $sheet->setCellValue($coord, $value);
    }

    private function writeValueBelowLabel($sheet, array $labels, string $value, int $colOffset = 0, int $rowOffset = 1): void
    {
        $pos = $this->findCellByLabelsExact($sheet, $labels);
        if (!$pos) return;

        $row = $pos->row;
        $colIndex = $pos->colIndex;
        $targetRow = $row + $rowOffset;
        $targetColIndex = max(1, $colIndex + $colOffset);
        $coord = Coordinate::stringFromColumnIndex($targetColIndex) . $targetRow;
        $sheet->setCellValue($coord, $value);
    }

    private function writeValueOnLine($sheet, array $labels, string $value, ?int $startRow = null, int $extraColOffset = 0, bool $wrapText = false): void
    {
        $pos = $this->findCellByLabelsExact($sheet, $labels, $startRow);
        if (!$pos) return;

        $labelCoord = $pos->coord;
        $targetCoord = $this->getFirstCellAfterMergedRangeOnRow($sheet, $labelCoord);
        if (!$targetCoord) {
            // fallback: just write one column to the right
            $targetCoord = Coordinate::stringFromColumnIndex($pos->colIndex + 1) . $pos->row;
        }

        // Optionally push the value further to the right by a few columns.
        if ($extraColOffset !== 0) {
            [$colLetter, $row] = Coordinate::coordinateFromString($targetCoord);
            $baseIndex = Coordinate::columnIndexFromString($colLetter);
            $newIndex = max(1, $baseIndex + $extraColOffset);
            $targetCoord = Coordinate::stringFromColumnIndex($newIndex) . $row;
        }

        $sheet->setCellValue($targetCoord, $value);

        $alignment = $sheet->getStyle($targetCoord)->getAlignment();
        $alignment->setHorizontal('left')->setVertical('center');
        if ($wrapText) {
            $alignment->setWrapText(true);
        }
    }

    /**
     * Set the row height of the Purpose value row so long/multi-line purpose text displays fully,
     * set vertical alignment to top, and collapse empty rows between Purpose and Objectives to remove gap.
     */
    private function setPurposeRowHeight($sheet, string $purposeText, ?int $startRow = null): void
    {
        $pos = $this->findCellByLabelsExact($sheet, ['Purpose:'], $startRow);
        if (!$pos) {
            return;
        }
        $purposeRow = $pos->row;
        // For CERTIFICATION section (startRow provided), estimate wrapped lines; for main TO, use explicit lines only.
        $explicitLines = max(1, substr_count($purposeText, "\n") + 1);
        if ($startRow !== null) {
            // CERTIFICATION TO TRAVEL section: generous estimate so all content displays.
            $textLength = mb_strlen($purposeText);
            $charsPerWrappedLine = 55; // Narrower columns in CERTIFICATION block.
            $wrappedLines = max(1, (int) ceil($textLength / $charsPerWrappedLine));
            $lines = max($explicitLines, $wrappedLines) + 2; // Extra buffer so nothing is cut off.
            $pointsPerLine = 16;
        } else {
            // Main TO section: keep original simple calculation (already working).
            $lines = $explicitLines;
            $pointsPerLine = 12;
        }
        $height = min(409, (float) ($pointsPerLine * $lines));
        $sheet->getRowDimension($purposeRow)->setRowHeight($height);

        // Purpose value cell: align to top so no empty space at top of cell.
        $labelCoord = $pos->coord;
        $targetCoord = $this->getFirstCellAfterMergedRangeOnRow($sheet, $labelCoord);
        if (!$targetCoord) {
            $targetCoord = Coordinate::stringFromColumnIndex($pos->colIndex + 1) . $pos->row;
        }
        $sheet->getStyle($targetCoord)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

        // Ensure at least one row of spacing below Purpose (between Purpose and Objectives).
        $objPos = $this->findCellByLabelsExact($sheet, ['Objectives:'], $startRow);
        if ($objPos && $objPos->row > $purposeRow + 1) {
            $spacingHeight = 12.0; // One row of visual spacing
            $gapRows = $objPos->row - $purposeRow - 1;
            if ($gapRows >= 1) {
                // Keep the first row after Purpose at spacing height, collapse any extra rows.
                $sheet->getRowDimension($purposeRow + 1)->setRowHeight($spacingHeight);
                if ($gapRows > 1) {
                    $minHeight = 3.0;
                    for ($r = $purposeRow + 2; $r < $objPos->row; $r++) {
                        $sheet->getRowDimension($r)->setRowHeight($minHeight);
                    }
                }
            }
        }
    }

    /**
     * Collapse empty rows between two labeled sections so there is no visible gap.
     * When $hideRows is true, rows are hidden (removes "double line" empty cell); otherwise height set to 3pt.
     */
    private function collapseEmptyRowsBetween($sheet, array $labelAfter, array $labelBefore, ?int $startRow = null, bool $hideRows = false): void
    {
        $posAfter = $this->findCellByLabelsExact($sheet, $labelAfter, $startRow);
        $posBefore = $this->findCellByLabelsExact($sheet, $labelBefore, $startRow);
        if (!$posAfter || !$posBefore || $posBefore->row <= $posAfter->row + 1) {
            return;
        }
        for ($r = $posAfter->row + 1; $r < $posBefore->row; $r++) {
            if ($hideRows) {
                $sheet->getRowDimension($r)->setVisible(false);
                $sheet->getRowDimension($r)->setZeroHeight(true);
            } else {
                $sheet->getRowDimension($r)->setRowHeight(3.0);
            }
        }
    }

    private function embedSignatureNearLabel($sheet, array $labels, ?string $signaturePath, int $colOffset, int $rowOffset, int $width, int $height): void
    {
        if (!$signaturePath) {
            return;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        if (!$disk->exists($signaturePath)) {
            return;
        }

        $pos = $this->findCellByLabelsExact($sheet, $labels);
        if (!$pos) return;

        $row = $pos->row;
        $colIndex = $pos->colIndex;
        $targetRow = $row + $rowOffset;
        $targetColIndex = max(1, $colIndex + $colOffset);
        $targetColLetter = Coordinate::stringFromColumnIndex($targetColIndex);
        $coord = $targetColLetter . $targetRow;

        // PhpSpreadsheet drawings require a real filesystem path.
        $absolute = $disk->path($signaturePath);
        if (!is_file($absolute)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Signature');
        $drawing->setPath($absolute);
        $drawing->setCoordinates($coord);
        $drawing->setWidth($width);
        $drawing->setHeight($height);
        $drawing->setOffsetX(5);
        $drawing->setOffsetY(2);
        $drawing->setWorksheet($sheet);
    }

    private function placeSignatureAt($sheet, ?string $signaturePath, string $coord, int $width, int $height, int $offsetX = 0, int $offsetY = -5): void
    {
        if (!$signaturePath) {
            return;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        if (!$disk->exists($signaturePath)) {
            return;
        }

        $absolute = $disk->path($signaturePath);
        if (!is_file($absolute)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Signature');
        $drawing->setPath($absolute);
        $drawing->setCoordinates($coord);
        $drawing->setWidth($width);
        $drawing->setHeight($height);
        $drawing->setOffsetX($offsetX);
        $drawing->setOffsetY($offsetY);
        $drawing->setWorksheet($sheet);
    }

    private function formatPerDiems(TravelOrder $travelOrder): string
    {
        $amount = $travelOrder->per_diems_expenses;
        $note = (string) ($travelOrder->per_diems_note ?? '');

        $parts = [];
        if ($amount !== null && $amount !== '') {
            $parts[] = number_format((float) $amount, 2, '.', ',');
        }
        if (trim($note) !== '') {
            $parts[] = trim($note);
        }

        return trim(implode(' ', $parts));
    }

    private function formatDateForExcel(mixed $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        try {
            // Example: February 03, 2026
            return Carbon::parse((string) $date)->format('F d, Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    /**
     * Exact label finder (case-insensitive, whitespace-normalized).
     * Optional $startRow lets us search below a section (for Certification to Travel).
     */
    private function findCellByLabelsExact($sheet, array $labels, ?int $startRow = null): ?object
    {
        $labelsNorm = array_map(fn($s) => $this->normCellText((string) $s), $labels);
        $rows = $sheet->toArray(null, true, true, true);

        foreach ($rows as $rowNum => $row) {
            $rowNum = (int) $rowNum;
            if ($startRow !== null && $rowNum < $startRow) continue;

            foreach ($row as $colLetter => $cellValue) {
                $hay = $this->normCellText((string) $cellValue);
                if ($hay === '') continue;

                foreach ($labelsNorm as $needle) {
                    if ($needle === '') continue;
                    if ($hay === $needle) {
                        $colIndex = Coordinate::columnIndexFromString((string) $colLetter);
                        $coord = (string) $colLetter . $rowNum;
                        return (object) [
                            'row' => $rowNum,
                            'colIndex' => $colIndex,
                            'coord' => $coord,
                        ];
                    }
                }
            }
        }

        return null;
    }

    private function normCellText(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s; // collapse spaces/newlines
        return mb_strtolower(trim($s));
    }

    /**
     * Given a coordinate that may be inside a merged label cell,
     * returns the first cell coordinate immediately after that merged range on the same row.
     */
    private function getFirstCellAfterMergedRangeOnRow($sheet, string $coord): ?string
    {
        [$col, $row] = Coordinate::coordinateFromString($coord);
        $row = (int) $row;
        $colIndex = Coordinate::columnIndexFromString($col);

        $endColIndex = $colIndex;
        foreach (array_keys($sheet->getMergeCells()) as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            $startColIndex = (int) $start[0];
            $startRow = (int) $start[1];
            $endCol = (int) $end[0];
            $endRow = (int) $end[1];

            if ($row >= $startRow && $row <= $endRow && $colIndex >= $startColIndex && $colIndex <= $endCol) {
                $endColIndex = max($endColIndex, $endCol);
                break;
            }
        }

        $targetColIndex = $endColIndex + 1;
        if ($targetColIndex < 1) return null;
        return Coordinate::stringFromColumnIndex($targetColIndex) . $row;
    }

    /**
     * Unmerge any merged range that contains at least one of the given coordinates.
     * Use before writing footer labels/names so each cell displays its own value.
     */
    private function unmergeCellsContaining($sheet, array $coords): void
    {
        $toUnmerge = [];
        foreach (array_keys($sheet->getMergeCells()) as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            $startColIndex = (int) $start[0];
            $startRow = (int) $start[1];
            $endCol = (int) $end[0];
            $endRow = (int) $end[1];
            foreach ($coords as $coord) {
                [$col, $row] = Coordinate::coordinateFromString($coord);
                $row = (int) $row;
                $colIndex = Coordinate::columnIndexFromString($col);
                if ($row >= $startRow && $row <= $endRow && $colIndex >= $startColIndex && $colIndex <= $endCol) {
                    $toUnmerge[$range] = true;
                    break;
                }
            }
        }
        foreach (array_keys($toUnmerge) as $range) {
            $sheet->unmergeCells($range);
        }
    }

    /**
     * If the given coordinate is inside a merged range, return the top-left cell of that range (where value is stored). Otherwise return the coord.
     */
    private function getMergeOriginCell($sheet, string $coord): string
    {
        [$col, $row] = Coordinate::coordinateFromString($coord);
        $row = (int) $row;
        $colIndex = Coordinate::columnIndexFromString($col);

        foreach (array_keys($sheet->getMergeCells()) as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            $startColIndex = (int) $start[0];
            $startRow = (int) $start[1];
            $endCol = (int) $end[0];
            $endRow = (int) $end[1];

            if ($row >= $startRow && $row <= $endRow && $colIndex >= $startColIndex && $colIndex <= $endCol) {
                return Coordinate::stringFromColumnIndex($startColIndex) . $startRow;
            }
        }

        return $coord;
    }

    /**
     * Replace the first exact cell that matches $searchText with $replacement.
     * If $nearRow provided, it will search starting from that row.
     */
    private function replaceExactCellText($sheet, string $searchText, string $replacement, ?int $nearRow = null): void
    {
        $pos = $this->findCellByLabelsExact($sheet, [$searchText], $nearRow);
        if (!$pos) return;
        $sheet->setCellValue($pos->coord, $replacement);
        $sheet->getStyle($pos->coord)->getAlignment()->setHorizontal('center')->setVertical('center');
    }

    // (Header helper methods removed intentionally)

    /**
     * Download an attachment (own travel order only).
     */
    public function downloadAttachment(Request $request, TravelOrderAttachment $attachment): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        $travelOrder = $attachment->travelOrder;
        if (!$travelOrder || (int) $travelOrder->personnel_id !== (int) $personnel->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        if (!$disk->exists($attachment->file_path)) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }

        return $disk->download(
            $attachment->file_path,
            $attachment->file_name,
            ['Content-Type' => $disk->mimeType($attachment->file_path)]
        );
    }

    /**
     * Store uploaded attachment files for the travel order.
     * Max 20 MB per file. Ensure php.ini: upload_max_filesize and post_max_size >= 20M.
     */
    protected function storeAttachments(Request $request, TravelOrder $travelOrder): void
    {
        $allowedTypes = ['itinerary', 'memorandum', 'invitation', 'other'];
        $maxSizeMb = 20;
        $maxSizeBytes = $maxSizeMb * 1024 * 1024;

        $files = $request->file('attachments');
        if (!$files) {
            return;
        }
        if (!is_array($files)) {
            $files = [$files];
        }

        $types = $request->input('attachment_types', []);
        if (!is_array($types)) {
            $types = [];
        }

        foreach ($files as $index => $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            if ($file->getSize() > $maxSizeBytes) {
                continue;
            }

            $type = $types[$index] ?? 'other';
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'other';
            }

            $filename = 'to_' . $travelOrder->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('travel-order-attachments', $filename, 'public');

            TravelOrderAttachment::create([
                'travel_order_id' => $travelOrder->id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'type' => $type,
            ]);
        }
    }
}
