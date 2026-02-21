<?php

namespace App\Http\Controllers;

use App\Models\IctAdmin;
use App\Models\Personnel;
use App\Models\Director;
use App\Models\TravelOrder;
use App\Models\TravelOrderApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportsController extends Controller
{
    /**
     * Ensure the authenticated user is ICT Admin.
     */
    protected function ensureIctAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof IctAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Only ICT Admin users can access this resource.',
            ], 403);
        }

        return null;
    }

    /**
     * Get analytics data for reports dashboard.
     */
    public function analytics(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        // Date range filters (optional)
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        // User Statistics
        $userStats = [
            'total_personnel' => Personnel::where('is_active', true)->count(),
            'total_directors' => Director::where('is_active', true)->count(),
            'total_users' => Personnel::where('is_active', true)->count() + Director::where('is_active', true)->count(),
            'inactive_personnel' => Personnel::where('is_active', false)->count(),
            'inactive_directors' => Director::where('is_active', false)->count(),
        ];

        // Travel Order Statistics
        $travelOrderQuery = TravelOrder::query();
        if ($dateFrom) {
            $travelOrderQuery->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $travelOrderQuery->where('created_at', '<=', $dateTo);
        }

        $travelOrderStats = [
            'total' => (clone $travelOrderQuery)->count(),
            'draft' => (clone $travelOrderQuery)->where('status', 'draft')->count(),
            'pending' => (clone $travelOrderQuery)->where('status', 'pending')->count(),
            'approved' => (clone $travelOrderQuery)->where('status', 'approved')->count(),
            'rejected' => (clone $travelOrderQuery)->where('status', 'rejected')->count(),
        ];

        // Monthly Travel Orders (last 12 months)
        $monthlyData = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();
            
            $monthQuery = TravelOrder::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd]);
            
            if ($dateFrom && Carbon::parse($dateFrom)->gt($monthStart)) {
                $monthQuery->where('created_at', '>=', $dateFrom);
            }
            if ($dateTo && Carbon::parse($dateTo)->lt($monthEnd)) {
                $monthQuery->where('created_at', '<=', $dateTo);
            }

            $monthlyData[] = [
                'month' => $monthStart->format('M Y'),
                'month_short' => $monthStart->format('M'),
                'year' => $monthStart->format('Y'),
                'total' => (clone $monthQuery)->count(),
                'draft' => (clone $monthQuery)->where('status', 'draft')->count(),
                'pending' => (clone $monthQuery)->where('status', 'pending')->count(),
                'approved' => (clone $monthQuery)->where('status', 'approved')->count(),
                'rejected' => (clone $monthQuery)->where('status', 'rejected')->count(),
            ];
        }

        // Status Distribution
        $statusDistribution = [
            ['name' => 'Draft', 'value' => $travelOrderStats['draft'], 'color' => '#6c757d'],
            ['name' => 'Pending', 'value' => $travelOrderStats['pending'], 'color' => '#ffb300'],
            ['name' => 'Approved', 'value' => $travelOrderStats['approved'], 'color' => '#28a745'],
            ['name' => 'Rejected', 'value' => $travelOrderStats['rejected'], 'color' => '#dc3545'],
        ];

        // Approval Statistics
        $approvalStats = [
            'total_approvals' => TravelOrderApproval::query()
                ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
                ->when($dateTo, fn($q) => $q->where('created_at', '<=', $dateTo))
                ->count(),
            'recommended' => TravelOrderApproval::query()
                ->where('status', 'recommended')
                ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
                ->when($dateTo, fn($q) => $q->where('created_at', '<=', $dateTo))
                ->count(),
            'approved' => TravelOrderApproval::query()
                ->where('status', 'approved')
                ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
                ->when($dateTo, fn($q) => $q->where('created_at', '<=', $dateTo))
                ->count(),
            'rejected' => TravelOrderApproval::query()
                ->where('status', 'rejected')
                ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
                ->when($dateTo, fn($q) => $q->where('created_at', '<=', $dateTo))
                ->count(),
        ];

        // Top Personnel by Travel Orders
        $topPersonnelQuery = Personnel::query()
            ->withCount([
                'travelOrders' => function ($query) use ($dateFrom, $dateTo) {
                    if ($dateFrom) $query->where('created_at', '>=', $dateFrom);
                    if ($dateTo) $query->where('created_at', '<=', $dateTo);
                }
            ])
            ->having('travel_orders_count', '>', 0)
            ->orderByDesc('travel_orders_count')
            ->limit(10);

        $topPersonnel = $topPersonnelQuery->get()->map(function ($personnel) {
            return [
                'id' => $personnel->id,
                'name' => trim(collect([
                    $personnel->first_name,
                    $personnel->middle_name,
                    $personnel->last_name,
                ])->filter()->implode(' ')),
                'department' => $personnel->department ?? 'N/A',
                'count' => $personnel->travel_orders_count,
            ];
        });

        // Weekly Activity (last 8 weeks)
        $weeklyData = [];
        for ($i = 7; $i >= 0; $i--) {
            $weekStart = Carbon::now()->subWeeks($i)->startOfWeek();
            $weekEnd = Carbon::now()->subWeeks($i)->endOfWeek();
            
            $weekQuery = TravelOrder::query()
                ->whereBetween('created_at', [$weekStart, $weekEnd]);
            
            if ($dateFrom && Carbon::parse($dateFrom)->gt($weekStart)) {
                $weekQuery->where('created_at', '>=', $dateFrom);
            }
            if ($dateTo && Carbon::parse($dateTo)->lt($weekEnd)) {
                $weekQuery->where('created_at', '<=', $dateTo);
            }

            $weeklyData[] = [
                'week' => $weekStart->format('M d'),
                'week_label' => "Week " . ($weekStart->format('W')),
                'total' => (clone $weekQuery)->count(),
                'approved' => (clone $weekQuery)->where('status', 'approved')->count(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user_stats' => $userStats,
                'travel_order_stats' => $travelOrderStats,
                'monthly_data' => $monthlyData,
                'weekly_data' => $weeklyData,
                'status_distribution' => $statusDistribution,
                'approval_stats' => $approvalStats,
                'top_personnel' => $topPersonnel,
            ],
        ]);
    }
}
