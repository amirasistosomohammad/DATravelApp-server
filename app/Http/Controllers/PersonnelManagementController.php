<?php

namespace App\Http\Controllers;

use App\Models\IctAdmin;
use App\Models\Personnel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PersonnelManagementController extends Controller
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
                'message' => 'Only ICT Admin users are allowed to manage personnel.',
            ], 403);
        }

        return null;
    }

    /**
     * List personnel with filters, sorting and pagination.
     */
    public function index(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $query = Personnel::query();

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%");
            });
        }

        // Status filter
        $status = $request->query('status', 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        // Sorting
        $allowedSorts = ['first_name', 'last_name', 'username', 'department', 'created_at'];
        $sort = $request->query('sort', 'last_name');
        $direction = $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc';

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'last_name';
        }

        // For name sorting, sort by last_name then first_name
        if ($sort === 'first_name' || $sort === 'last_name') {
            $query->orderBy('last_name', $direction)
                  ->orderBy('first_name', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        $perPage = (int) $request->query('per_page', 10);
        if ($perPage <= 0) {
            $perPage = 10;
        }

        $paginator = $query->paginate($perPage);

        $total = Personnel::count();
        $active = Personnel::where('is_active', true)->count();
        $inactive = Personnel::where('is_active', false)->count();

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
                    'active' => $active,
                    'inactive' => $inactive,
                ],
            ],
        ]);
    }

    /**
     * Create a new personnel user.
     */
    public function store(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:personnel,username'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required_with:password', 'same:password'],
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // Required if is_active is explicitly false/0
            'reason_for_deactivation' => ['nullable', 'string', 'max:500', 'required_if:is_active,0'],
        ]);

        // Handle avatar upload
        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = 'personnel_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('personnel-avatars', $filename, 'public');
            $avatarPath = $path;
        }

        // IMPORTANT:
        // `department` in the DB is NOT NULL with a default value.
        // If we pass NULL explicitly, MySQL will throw an integrity error.
        // So we only include `department` if the client actually provided it.
        $createData = [
            'username' => $validated['username'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'position' => $validated['position'] ?? null,
            'avatar_path' => $avatarPath,
            'is_active' => $validated['is_active'] ?? true,
            'reason_for_deactivation' => $validated['reason_for_deactivation'] ?? null,
        ];

        if (array_key_exists('department', $validated) && trim((string) $validated['department']) !== '') {
            $createData['department'] = $validated['department'];
        }

        $personnel = Personnel::create($createData);

        return response()->json([
            'success' => true,
            'message' => 'Personnel created successfully.',
            'data' => $personnel,
        ], 201);
    }

    /**
     * Update an existing personnel user.
     */
    public function update(Request $request, Personnel $personnel)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('personnel', 'username')->ignore($personnel->id),
            ],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'password_confirmation' => ['nullable', 'required_with:password', 'same:password'],
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            // Required if is_active is explicitly false/0
            'reason_for_deactivation' => ['nullable', 'string', 'max:500', 'required_if:is_active,0'],
        ]);

        // Normalize empty strings to NULL so clearing fields from the UI works.
        // (department is nullable now; position was already nullable)
        if (array_key_exists('department', $validated) && trim((string) $validated['department']) === '') {
            $validated['department'] = null;
        }
        if (array_key_exists('position', $validated) && trim((string) $validated['position']) === '') {
            $validated['position'] = null;
        }

        // Handle password update
        if (array_key_exists('password', $validated) && $validated['password']) {
            $personnel->password = Hash::make($validated['password']);
            unset($validated['password']);
            unset($validated['password_confirmation']);
        } else {
            unset($validated['password']);
            unset($validated['password_confirmation']);
        }

        // Handle avatar removal
        if ($request->has('remove_avatar') && $request->input('remove_avatar')) {
            if ($personnel->avatar_path && Storage::disk('public')->exists($personnel->avatar_path)) {
                Storage::disk('public')->delete($personnel->avatar_path);
            }
            $validated['avatar_path'] = null;
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($personnel->avatar_path && Storage::disk('public')->exists($personnel->avatar_path)) {
                Storage::disk('public')->delete($personnel->avatar_path);
            }

            $file = $request->file('avatar');
            $filename = 'personnel_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('personnel-avatars', $filename, 'public');
            $validated['avatar_path'] = $path;
        }

        // Clear reason_for_deactivation if is_active is true
        if (isset($validated['is_active']) && $validated['is_active']) {
            $validated['reason_for_deactivation'] = null;
        }

        unset($validated['avatar']);
        unset($validated['remove_avatar']);

        $personnel->fill($validated);
        $personnel->save();

        return response()->json([
            'success' => true,
            'message' => 'Personnel updated successfully.',
            'data' => $personnel,
        ]);
    }

    /**
     * Delete a personnel user.
     */
    public function destroy(Request $request, Personnel $personnel)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        // Delete avatar if exists
        if ($personnel->avatar_path && Storage::disk('public')->exists($personnel->avatar_path)) {
            Storage::disk('public')->delete($personnel->avatar_path);
        }

        $personnel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Personnel deleted successfully.',
        ]);
    }

    /**
     * Serve personnel avatar image.
     */
    public function getAvatar($filename)
    {
        $path = 'personnel-avatars/' . $filename;
        
        if (!Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $file = Storage::disk('public')->get($path);
        $type = Storage::disk('public')->mimeType($path);

        return response($file, 200)->header('Content-Type', $type);
    }
}


