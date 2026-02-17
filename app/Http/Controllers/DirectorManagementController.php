<?php

namespace App\Http\Controllers;

use App\Models\Director;
use App\Models\IctAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DirectorManagementController extends Controller
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
                'message' => 'Only ICT Admin users are allowed to manage directors.',
            ], 403);
        }

        return null;
    }

    /**
     * List directors with filters, sorting and pagination.
     */
    public function index(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $query = Director::query();

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%")
                    ->orWhere('director_level', 'like', "%{$search}%")
                    ->orWhere('contact_information', 'like', "%{$search}%");
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
        $allowedSorts = ['first_name', 'last_name', 'username', 'department', 'position', 'created_at'];
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

        $total = Director::count();
        $active = Director::where('is_active', true)->count();
        $inactive = Director::where('is_active', false)->count();

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
     * Create a new director user.
     */
    public function store(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:directors,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:directors,email'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_information' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required_with:password', 'same:password'],
            'position' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'director_level' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'signature' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // Required if is_active is explicitly false/0
            'reason_for_deactivation' => ['nullable', 'string', 'max:500', 'required_if:is_active,0'],
        ]);

        // Handle avatar upload
        $avatarPath = null;
        $signaturePath = null;
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = 'director_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('director-avatars', $filename, 'public');
            $avatarPath = $path;
        }

        // Handle signature upload
        if ($request->hasFile('signature')) {
            $file = $request->file('signature');
            $filename = 'director_signature_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('director-signatures', $filename, 'public');
            $signaturePath = $path;
        }

        $fullNameParts = array_filter([
            $validated['first_name'] ?? null,
            $validated['middle_name'] ?? null,
            $validated['last_name'] ?? null,
        ], function ($value) {
            return $value !== null && trim((string) $value) !== '';
        });

        $createData = [
            'username' => $validated['username'],
            'email' => $validated['email'] ?? null,
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'name' => trim(implode(' ', $fullNameParts)),
            'phone' => $validated['phone'] ?? null,
            'contact_information' => $validated['contact_information'] ?? null,
            'password' => Hash::make($validated['password']),
            'position' => $validated['position'] ?? null,
            'department' => $validated['department'] ?? null,
            'director_level' => $validated['director_level'] ?? null,
            'avatar_path' => $avatarPath,
            'signature_path' => $signaturePath,
            'is_active' => $validated['is_active'] ?? true,
            'reason_for_deactivation' => $validated['reason_for_deactivation'] ?? null,
        ];

        // Normalize empty strings to NULL so clearing fields works.
        foreach (['department', 'position', 'phone', 'director_level', 'email', 'contact_information'] as $field) {
            if (array_key_exists($field, $createData) && trim((string) $createData[$field]) === '') {
                $createData[$field] = null;
            }
        }

        $director = Director::create($createData);

        return response()->json([
            'success' => true,
            'message' => 'Director created successfully.',
            'data' => $director,
        ], 201);
    }

    /**
     * Update an existing director user.
     */
    public function update(Request $request, Director $director)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('directors', 'username')->ignore($director->id),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('directors', 'email')->ignore($director->id),
            ],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_information' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'password_confirmation' => ['nullable', 'required_with:password', 'same:password'],
            'position' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'director_level' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            // Required if is_active is explicitly false/0
            'reason_for_deactivation' => ['nullable', 'string', 'max:500', 'required_if:is_active,0'],
        ]);

        // Normalize empty strings to NULL so clearing fields works.
        foreach (['department', 'position', 'phone', 'director_level', 'email', 'contact_information'] as $field) {
            if (array_key_exists($field, $validated) && trim((string) $validated[$field]) === '') {
                $validated[$field] = null;
            }
        }

        // Handle password update
        if (array_key_exists('password', $validated) && $validated['password']) {
            $director->password = Hash::make($validated['password']);
            unset($validated['password']);
            unset($validated['password_confirmation']);
        } else {
            unset($validated['password']);
            unset($validated['password_confirmation']);
        }

        // Handle avatar removal
        if ($request->has('remove_avatar') && $request->input('remove_avatar')) {
            if ($director->avatar_path && Storage::disk('public')->exists($director->avatar_path)) {
                Storage::disk('public')->delete($director->avatar_path);
            }
            $validated['avatar_path'] = null;
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($director->avatar_path && Storage::disk('public')->exists($director->avatar_path)) {
                Storage::disk('public')->delete($director->avatar_path);
            }

            $file = $request->file('avatar');
            $filename = 'director_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('director-avatars', $filename, 'public');
            $validated['avatar_path'] = $path;
        }

        // Handle signature upload
        if ($request->hasFile('signature')) {
            if ($director->signature_path && Storage::disk('public')->exists($director->signature_path)) {
                Storage::disk('public')->delete($director->signature_path);
            }
            $file = $request->file('signature');
            $filename = 'director_signature_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('director-signatures', $filename, 'public');
            $validated['signature_path'] = $path;
        }

        // Clear reason_for_deactivation if is_active is true
        if (isset($validated['is_active']) && $validated['is_active']) {
            $validated['reason_for_deactivation'] = null;
        }

        // Update full name if any name fields changed
        if (
            array_key_exists('first_name', $validated) ||
            array_key_exists('middle_name', $validated) ||
            array_key_exists('last_name', $validated)
        ) {
            $first = array_key_exists('first_name', $validated) ? $validated['first_name'] : $director->first_name;
            $middle = array_key_exists('middle_name', $validated) ? $validated['middle_name'] : $director->middle_name;
            $last = array_key_exists('last_name', $validated) ? $validated['last_name'] : $director->last_name;

            $nameParts = array_filter([$first, $middle, $last], function ($value) {
                return $value !== null && trim((string) $value) !== '';
            });

            $validated['name'] = trim(implode(' ', $nameParts));
        }

        unset($validated['avatar']);
        unset($validated['signature']);
        unset($validated['remove_avatar']);

        $director->fill($validated);
        $director->save();

        return response()->json([
            'success' => true,
            'message' => 'Director updated successfully.',
            'data' => $director,
        ]);
    }

    /**
     * Delete a director user.
     */
    public function destroy(Request $request, Director $director)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        // Delete avatar if exists
        if ($director->avatar_path && Storage::disk('public')->exists($director->avatar_path)) {
            Storage::disk('public')->delete($director->avatar_path);
        }

        $director->delete();

        return response()->json([
            'success' => true,
            'message' => 'Director deleted successfully.',
        ]);
    }

    /**
     * Serve director avatar image.
     */
    public function getAvatar($filename)
    {
        $path = 'director-avatars/' . $filename;

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            abort(404);
        }

        $file = $disk->get($path);
        $type = $disk->mimeType($path);

        return response($file, 200)->header('Content-Type', $type);
    }
}

