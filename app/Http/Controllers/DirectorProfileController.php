<?php

namespace App\Http\Controllers;

use App\Models\Director;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DirectorProfileController extends Controller
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
     * Get the current director's signature URL (if any).
     */
    public function getSignature(Request $request)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'signature_url' => $director->signature_path
                    ? asset('storage/' . $director->signature_path)
                    : null,
            ],
        ]);
    }

    /**
     * Upload or remove the current director's signature image.
     */
    public function updateSignature(Request $request)
    {
        if ($resp = $this->ensureDirector($request)) {
            return $resp;
        }

        /** @var Director $director */
        $director = $request->user();

        $validated = $request->validate([
            'signature' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'remove_signature' => ['sometimes', 'boolean'],
        ]);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        // Remove existing signature if requested
        if (!empty($validated['remove_signature']) && $director->signature_path) {
            if ($disk->exists($director->signature_path)) {
                $disk->delete($director->signature_path);
            }
            $director->signature_path = null;
        }

        // Upload new signature file
        if ($request->hasFile('signature')) {
            if ($director->signature_path && $disk->exists($director->signature_path)) {
                $disk->delete($director->signature_path);
            }

            $file = $request->file('signature');
            $filename = 'director_signature_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('director-signatures', $filename, 'public');
            $director->signature_path = $path;
        }

        $director->save();

        return response()->json([
            'success' => true,
            'message' => 'Signature updated successfully.',
            'data' => [
                'signature_url' => $director->signature_path
                    ? asset('storage/' . $director->signature_path)
                    : null,
            ],
        ]);
    }
}

