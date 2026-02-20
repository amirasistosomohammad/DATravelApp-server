<?php

namespace App\Http\Controllers;

use App\Models\Personnel;
use Illuminate\Http\Request;

class PersonnelProfileController extends Controller
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
                'message' => 'Only personnel can access this resource.',
            ], 403);
        }

        return null;
    }

    /**
     * Get the current personnel's profile (database details).
     */
    public function show(Request $request)
    {
        if ($resp = $this->ensurePersonnel($request)) {
            return $resp;
        }

        /** @var Personnel $personnel */
        $personnel = $request->user();

        $data = [
            'id' => $personnel->id,
            'username' => $personnel->username,
            'name' => $personnel->name,
            'first_name' => $personnel->first_name ?? null,
            'middle_name' => $personnel->middle_name ?? null,
            'last_name' => $personnel->last_name ?? null,
            'position' => $personnel->position ?? null,
            'department' => $personnel->department ?? 'Department of Agriculture',
            'phone' => $personnel->phone ?? null,
            'is_active' => $personnel->is_active ?? true,
        ];

        if ($personnel->avatar_path) {
            $data['avatar'] = str_starts_with($personnel->avatar_path, 'http')
                ? $personnel->avatar_path
                : url('/storage/' . ltrim($personnel->avatar_path, '/'));
        } else {
            $data['avatar'] = null;
        }

        return response()->json([
            'success' => true,
            'data' => ['profile' => $data],
        ], 200);
    }
}
