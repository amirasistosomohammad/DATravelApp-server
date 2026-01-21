<?php

namespace App\Http\Controllers;

use App\Models\IctAdmin;
use App\Models\TimeLog;
use Illuminate\Http\Request;

class TimeLoggingController extends Controller
{
    /**
     * Ensure the authenticated user is an ICT Admin.
     */
    protected function ensureIctAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof IctAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Only ICT Admin users are allowed to manage time logs.',
            ], 403);
        }

        return null;
    }

    /**
     * List time logs with filters, sorting and pagination.
     */
    public function index(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $query = TimeLog::with(['personnel', 'director']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('remarks', 'like', "%{$search}%")
                    ->orWhere('log_date', 'like', "%{$search}%")
                    ->orWhere('time_in', 'like', "%{$search}%")
                    ->orWhere('time_out', 'like', "%{$search}%")
                    ->orWhereHas('personnel', function ($p) use ($search) {
                        $p->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%")
                            ->orWhere('department', 'like', "%{$search}%")
                            ->orWhere('position', 'like', "%{$search}%");
                    })
                    ->orWhereHas('director', function ($d) use ($search) {
                        $d->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%")
                            ->orWhere('department', 'like', "%{$search}%")
                            ->orWhere('position', 'like', "%{$search}%");
                    });
            });
        }

        $status = $request->query('status', 'all');
        if ($status === 'open') {
            $query->whereNull('time_out');
        } elseif ($status === 'closed') {
            $query->whereNotNull('time_out');
        }

        $allowedSorts = ['log_date', 'time_in', 'time_out', 'created_at'];
        $sort = $request->query('sort', 'log_date');
        $direction = $request->query('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'log_date';
        }

        $query->orderBy($sort, $direction)->orderBy('time_in', $direction);

        $perPage = (int) $request->query('per_page', 10);
        if ($perPage <= 0) {
            $perPage = 10;
        }

        $paginator = $query->paginate($perPage);

        $total = TimeLog::count();
        $open = TimeLog::whereNull('time_out')->count();
        $closed = TimeLog::whereNotNull('time_out')->count();

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
                'stats' => [
                    'total' => $total,
                    'open' => $open,
                    'closed' => $closed,
                ],
            ],
        ]);
    }

    /**
     * Create a new time log.
     */
    public function store(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'personnel_id' => ['nullable', 'integer', 'exists:personnel,id'],
            'director_id' => ['nullable', 'integer', 'exists:directors,id'],
            'log_date' => ['required', 'date'],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if (empty($validated['personnel_id']) && empty($validated['director_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Personnel or director is required.',
                'errors' => [
                    'personnel_id' => ['Personnel or director is required.'],
                ],
            ], 422);
        }

        if (!empty($validated['personnel_id']) && !empty($validated['director_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only one user type can be selected per time log.',
                'errors' => [
                    'director_id' => ['Choose either personnel or director.'],
                ],
            ], 422);
        }

        if (!empty($validated['time_out']) && $validated['time_out'] < $validated['time_in']) {
            return response()->json([
                'success' => false,
                'message' => 'Time out must be after time in.',
                'errors' => [
                    'time_out' => ['Time out must be after time in.'],
                ],
            ], 422);
        }

        $timeLog = TimeLog::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Time log created successfully.',
            'data' => $timeLog->load(['personnel', 'director']),
        ], 201);
    }

    /**
     * Update an existing time log.
     */
    public function update(Request $request, TimeLog $timeLog)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'personnel_id' => ['nullable', 'integer', 'exists:personnel,id'],
            'director_id' => ['nullable', 'integer', 'exists:directors,id'],
            'log_date' => ['sometimes', 'date'],
            'time_in' => ['sometimes', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if (
            array_key_exists('personnel_id', $validated) ||
            array_key_exists('director_id', $validated)
        ) {
            $personnelId = $validated['personnel_id'] ?? $timeLog->personnel_id;
            $directorId = $validated['director_id'] ?? $timeLog->director_id;

            if (!$personnelId && !$directorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Personnel or director is required.',
                    'errors' => [
                        'personnel_id' => ['Personnel or director is required.'],
                    ],
                ], 422);
            }

            if ($personnelId && $directorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only one user type can be selected per time log.',
                    'errors' => [
                        'director_id' => ['Choose either personnel or director.'],
                    ],
                ], 422);
            }
        }

        $timeIn = $validated['time_in'] ?? $timeLog->time_in?->format('H:i');
        $timeOut = array_key_exists('time_out', $validated)
            ? $validated['time_out']
            : ($timeLog->time_out?->format('H:i'));

        if (!empty($timeOut) && !empty($timeIn) && $timeOut < $timeIn) {
            return response()->json([
                'success' => false,
                'message' => 'Time out must be after time in.',
                'errors' => [
                    'time_out' => ['Time out must be after time in.'],
                ],
            ], 422);
        }

        $timeLog->fill($validated);
        $timeLog->save();

        return response()->json([
            'success' => true,
            'message' => 'Time log updated successfully.',
            'data' => $timeLog->load(['personnel', 'director']),
        ]);
    }

    /**
     * Delete a time log.
     */
    public function destroy(Request $request, TimeLog $timeLog)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $timeLog->delete();

        return response()->json([
            'success' => true,
            'message' => 'Time log deleted successfully.',
        ]);
    }
}

