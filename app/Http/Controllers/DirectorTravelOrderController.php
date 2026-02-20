<?php

namespace App\Http\Controllers;

use App\Models\Director;
use App\Models\Personnel;
use App\Models\TravelOrder;
use App\Models\TravelOrderApproval;
use App\Models\TravelOrderAttachment;
use Illuminate\Http\Request;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DirectorTravelOrderController extends Controller
{
    /**
     * Approving directors (step 2) can only see/act after the recommending step is completed.
     */
    protected function recommendStepCompleted(int $travelOrderId): bool
    {
        $recommend = TravelOrderApproval::query()
            ->where('travel_order_id', $travelOrderId)
            ->where('step_order', 1)
            ->first();

        if (!$recommend) {
            return false;
        }

        return in_array($recommend->status, ['recommended', 'approved'], true);
    }
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

        // Only return TOs that are pending THIS director's action.
        // For approving directors (step 2), the TO becomes visible only after step 1 is completed.
        $pendingApprovals = TravelOrderApproval::query()
            ->where('director_id', $director->id)
            ->where('status', 'pending')
            ->get(['travel_order_id', 'step_order']);

        $visibleTravelOrderIds = $pendingApprovals
            ->filter(function ($a) {
                /** @var TravelOrderApproval $a */
                if ((int) $a->step_order === 1) return true;
                return $this->recommendStepCompleted((int) $a->travel_order_id);
            })
            ->pluck('travel_order_id');

        $query = TravelOrder::query()
            ->whereIn('id', $visibleTravelOrderIds)
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

        // Gate step 2 access until step 1 is completed.
        if ((int) $approval->step_order === 2 && !$this->recommendStepCompleted((int) $travelOrder->id)) {
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

        // Gate step 2 actions until step 1 is completed.
        if ((int) $approval->step_order === 2 && !$this->recommendStepCompleted((int) $travelOrder->id)) {
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
     * For "approved" filter: shows travel orders with overall status = approved where director was involved.
     * For "recommended" filter: shows travel orders where director's individual action was recommend.
     * For "rejected" filter: shows travel orders where director's individual action was reject.
     */
    public function history(Request $request)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        $status = $request->query('status'); // approved | rejected | recommended | all
        
        // Get all travel order IDs where this director has an approval record
        $allApprovalIds = TravelOrderApproval::query()
            ->where('director_id', $director->id)
            ->pluck('travel_order_id')
            ->unique();

        $query = TravelOrder::query()
            ->whereIn('id', $allApprovalIds)
            ->with(['attachments', 'personnel', 'approvals.director'])
            ->orderByDesc('updated_at');

        // Apply filters based on status
        if ($status === 'approved') {
            // Show travel orders with overall status = approved (where director was involved)
            $query->where('status', 'approved');
        } elseif ($status === 'recommended') {
            // Show travel orders where director's individual action was "recommend"
            $recommendedApprovalIds = TravelOrderApproval::query()
                ->where('director_id', $director->id)
                ->where('status', 'recommended')
                ->pluck('travel_order_id');
            $query->whereIn('id', $recommendedApprovalIds);
        } elseif ($status === 'rejected') {
            // Show travel orders where director's individual action was "reject"
            $rejectedApprovalIds = TravelOrderApproval::query()
                ->where('director_id', $director->id)
                ->where('status', 'rejected')
                ->pluck('travel_order_id');
            $query->whereIn('id', $rejectedApprovalIds);
        } else {
            // Show all: travel orders where director has acted (recommended, approved, or rejected)
            $actedApprovalIds = TravelOrderApproval::query()
                ->where('director_id', $director->id)
                ->whereIn('status', ['recommended', 'approved', 'rejected'])
                ->pluck('travel_order_id');
            $query->whereIn('id', $actedApprovalIds);
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

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return $disk->download(
            $attachment->file_path,
            $attachment->file_name,
            ['Content-Type' => $disk->mimeType($attachment->file_path)]
        );
    }

    /**
     * Export a travel order as Excel (directors can export any travel order they have access to).
     */
    public function exportExcel(Request $request, TravelOrder $travelOrder)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        if (!extension_loaded('zip')) {
            return response()->json([
                'success' => false,
                'message' => 'Excel export requires the PHP ZIP extension (ext-zip). Please enable it in your PHP configuration and restart the server.',
            ], 500);
        }

        /** @var Director $director */
        $director = $request->user();

        // Check if director has access to this travel order (has an approval record)
        $hasAccess = TravelOrderApproval::query()
            ->where('travel_order_id', $travelOrder->id)
            ->where('director_id', $director->id)
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order not found.',
            ], 404);
        }

        // Create a new request with personnel user to reuse TravelOrderController's exportExcel method
        $personnel = $travelOrder->personnel;
        if (!$personnel) {
            return response()->json([
                'success' => false,
                'message' => 'Travel order personnel not found.',
            ], 404);
        }

        // Create a new request instance with personnel user
        $newRequest = Request::create($request->url(), 'GET', $request->query());
        $newRequest->setUserResolver(function () use ($personnel) {
            return $personnel;
        });

        // Use TravelOrderController's exportExcel method
        $travelOrderController = new \App\Http\Controllers\TravelOrderController();
        return $travelOrderController->exportExcel($newRequest, $travelOrder);
    }
}
