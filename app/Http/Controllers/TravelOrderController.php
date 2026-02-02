<?php

namespace App\Http\Controllers;

use App\Models\Director;
use App\Models\Personnel;
use App\Models\TravelOrder;
use App\Models\TravelOrderApproval;
use App\Models\TravelOrderAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $travelOrder->load(['attachments', 'approvals.director']);

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
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'objectives' => ['nullable', 'string'],
            'per_diems_expenses' => ['nullable', 'numeric', 'min:0'],
            'appropriation' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
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
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'objectives' => ['nullable', 'string'],
            'per_diems_expenses' => ['nullable', 'numeric', 'min:0'],
            'appropriation' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $travelOrder->update($validated);

        // Handle attachment removals (client sends delete_ids)
        $deleteIds = $request->input('delete_attachment_ids', []);
        if (is_array($deleteIds) && !empty($deleteIds)) {
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

        foreach ($travelOrder->attachments as $att) {
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
     * Body: approving_director_id (required), recommending_director_id (optional).
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
            'approving_director_id' => ['required', 'integer', 'exists:directors,id'],
            'recommending_director_id' => ['nullable', 'integer', 'exists:directors,id', 'different:approving_director_id'],
        ]);

        $approvingDirectorId = (int) $validated['approving_director_id'];
        $recommendingDirectorId = isset($validated['recommending_director_id'])
            ? (int) $validated['recommending_director_id']
            : null;

        $approvingDirector = Director::find($approvingDirectorId);
        if (!$approvingDirector || !$approvingDirector->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Selected approving director is not active.',
            ], 422);
        }
        if ($recommendingDirectorId) {
            $recDirector = Director::find($recommendingDirectorId);
            if (!$recDirector || !$recDirector->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected recommending director is not active.',
                ], 422);
            }
        }

        \DB::transaction(function () use ($travelOrder, $recommendingDirectorId, $approvingDirectorId) {
            if ($recommendingDirectorId) {
                TravelOrderApproval::create([
                    'travel_order_id' => $travelOrder->id,
                    'director_id' => $recommendingDirectorId,
                    'step_order' => 1,
                    'status' => 'pending',
                ]);
            }
            TravelOrderApproval::create([
                'travel_order_id' => $travelOrder->id,
                'director_id' => $approvingDirectorId,
                'step_order' => $recommendingDirectorId ? 2 : 1,
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

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }

        return Storage::disk('public')->download(
            $attachment->file_path,
            $attachment->file_name,
            ['Content-Type' => Storage::disk('public')->mimeType($attachment->file_path)]
        );
    }

    /**
     * Store uploaded attachment files for the travel order.
     */
    protected function storeAttachments(Request $request, TravelOrder $travelOrder): void
    {
        $allowedTypes = ['itinerary', 'memorandum', 'invitation', 'other'];
        $maxSizeMb = 10;
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
