<?php

namespace App\Http\Controllers;

use App\Models\IctAdmin;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class IctAdminSettingsController extends Controller
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
                'message' => 'Only ICT Admin users can access system settings.',
            ], 403);
        }

        return null;
    }

    /**
     * Change password (ICT Admin only).
     */
    public function changePassword(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ], [
            'new_password.confirmed' => 'The new password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var IctAdmin $user */
        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => ['The current password is incorrect.'],
                ],
            ], 422);
        }

        // Assign plain password so the model's 'hashed' cast hashes it once (avoid double-hash).
        $user->password = $request->new_password;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ], 200);
    }

    /**
     * Build branding payload (logo text and logo URL). Used by getBranding and getBrandingPublic.
     */
    private function brandingPayload(): array
    {
        $logoText = SystemSetting::get('branding_logo_text', 'DATravelApp');
        $logoPath = SystemSetting::get('branding_logo_path');
        $logoUrl = null;
        if ($logoPath && Storage::disk('public')->exists($logoPath)) {
            $logoUrl = url(Storage::disk('public')->url($logoPath));
        }
        return [
            'logo_text' => $logoText,
            'logo_path' => $logoPath,
            'logo_url' => $logoUrl,
        ];
    }

    /**
     * Get branding settings (logo text and logo URL). ICT Admin only.
     */
    public function getBranding(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        return response()->json([
            'success' => true,
            'branding' => $this->brandingPayload(),
        ], 200);
    }

    /**
     * Get branding settings (public, no auth). For login page and topbar.
     */
    public function getBrandingPublic()
    {
        return response()->json([
            'success' => true,
            'branding' => $this->brandingPayload(),
        ], 200);
    }

    /**
     * Update branding settings (logo text and optional logo file).
     */
    public function updateBranding(Request $request)
    {
        if ($resp = $this->ensureIctAdmin($request)) {
            return $resp;
        }

        $validator = Validator::make($request->all(), [
            'logo_text' => 'nullable|string|max:255',
            'logo' => 'nullable|file|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('logo_text')) {
            SystemSetting::set('branding_logo_text', $request->logo_text ?: 'DATravelApp');
        }

        if ($request->hasFile('logo')) {
            $oldPath = SystemSetting::get('branding_logo_path');
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('logo')->store('branding', 'public');
            SystemSetting::set('branding_logo_path', $path);
        }

        return response()->json([
            'success' => true,
            'message' => 'Branding updated successfully.',
            'branding' => $this->brandingPayload(),
        ], 200);
    }
}
