<?php

namespace App\Http\Controllers;

use App\Models\Director;
use App\Models\TravelOrder;
use App\Models\TravelOrderApproval;
use App\Models\TravelOrderAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DirectorTravelOrderController extends Controller
{
    /**
     * Ensure the authenticated user is a Director.
     */
    protected function ensureDirector(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof Director) {
            return response()->json([
                'success' => false,
                'message' => 'Only directors can access this resource.',
            ], 403);
        }

        return null;
    }

    /**
     * List travel orders pending this director's action.
     */
    public function pending(Request $request)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        $approvalIds = TravelOrderApproval::query()
            ->where('director_id', $director->id)
            ->where('status', 'pending')
            ->pluck('travel_order_id');

        $query = TravelOrder::query()
            ->whereIn('id', $approvalIds)
            ->where('status', 'pending')
            ->with(['attachments', 'personnel', 'approvals.director'])
            ->orderByDesc('submitted_at');

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
     * Show a single travel order for review (must be pending for this director).
     */
    public function show(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        $approval = TravelOrderApproval::query()
            ->where('travel_order_id', $travelOrder->id)
            ->where('director_id', $director->id)
            ->where('status', 'pending')
            ->first();

        if (!$approval) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found or not pending your action.',
            ], 404);
        }

        $travelOrder->load(['attachments', 'personnel', 'approvals.director']);
        $travelOrder->setRelation('current_approval', $approval);

        return response()->json([
            'success' => true,
            'data' => [
                'travel_order' => $travelOrder,
                'current_approval' => $approval,
            ],
        ]);
    }

    /**
     * Recommend, approve, or reject a travel order.
     * Body: action (recommend | approve | reject), remarks (optional).
     */
    public function action(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:recommend,approve,reject'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $approval = TravelOrderApproval::query()
            ->where('travel_order_id', $travelOrder->id)
            ->where('director_id', $director->id)
            ->where('status', 'pending')
            ->first();

        if (!$approval) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found or not pending your action.',
            ], 404);
        }

        $action = $validated['action'];
        $remarks = $validated['remarks'] ?? null;

        $isOnlyStep = $travelOrder->approvals()->count() === 1;
        $isRecommendStep = $approval->step_order === 1 && !$isOnlyStep;
        $isApproveStep = $approval->step_order === 2 || $isOnlyStep;

        if ($action === 'recommend') {
            if (!$isRecommendStep) {
                return response()->json([
                    'success' => false,
                    'message' => 'This step is not a recommending step.',
                ], 422);
            }
            $approval->update([
                'status' => 'recommended',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);
            $message = 'Travel order recommended successfully.';
        } elseif ($action === 'approve') {
            if (!$isApproveStep) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the final approver can approve.',
                ], 422);
            }
            $approval->update([
                'status' => 'approved',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);
            $travelOrder->update(['status' => 'approved']);
            $message = 'Travel order approved successfully.';
        } else {
            $approval->update([
                'status' => 'rejected',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);
            $travelOrder->update(['status' => 'rejected']);
            $message = 'Travel order rejected.';
        }

        $travelOrder->load(['attachments', 'personnel', 'approvals.director']);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $travelOrder,
        ]);
    }

    /**
     * History: travel orders this director has acted on (recommended, approved, rejected).
     */
    public function history(Request $request)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        $approvalIds = TravelOrderApproval::query()
            ->where('director_id', $director->id)
            ->whereIn('status', ['recommended', 'approved', 'rejected'])
            ->pluck('travel_order_id');

        $query = TravelOrder::query()
            ->whereIn('id', $approvalIds)
            ->with(['attachments', 'personnel', 'approvals.director'])
            ->orderByDesc('updated_at');

        $status = $request->query('status'); // approved | rejected | recommended | all
        if ($status && in_array($status, ['approved', 'rejected', 'recommended'], true)) {
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
     * Download an attachment for a travel order the director is reviewing.
     */
    public function downloadAttachment(Request $request, TravelOrderAttachment $attachment): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        $travelOrder = $attachment->travelOrder;
        if (!$travelOrder) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $hasAccess = TravelOrderApproval::query()
            ->where('travel_order_id', $travelOrder->id)
            ->where('director_id', $director->id)
            ->exists();

        if (!$hasAccess) {
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
}
