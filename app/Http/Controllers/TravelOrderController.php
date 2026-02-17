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

class TravelOrderController extends Controller
{
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

        $this->storeAttachments($request, $travelOrder);

        $travelOrder->load('attachments');

        return response()->json([
            'success' => true,
            'message' => 'Travel order updated successfully.',
            'data' => $travelOrder,
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
            \Log::warning('PDF export: PHP GD extension is not enabled; director signatures will not appear in the PDF.');
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
                \Log::error('PDF generation failed: GD extension not available', [
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
