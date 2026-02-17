<?php

namespace App\Http\Controllers;

use App\Models\Director;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
                'signature_url' => $this->signatureUrlFor($director),
            ],
        ]);
    }

    /**
     * Serve the director's signature image (signed URL, correct Content-Type).
     * Used in production so the image loads over HTTPS with proper headers.
     */
    public function serveSignatureImage(Request $request): BinaryFileResponse|\Illuminate\Http\Response
    {
        $directorId = $request->query('director');
        if (!$directorId) {
            abort(404);
        }

        $director = Director::find($directorId);
        if (!$director || !$director->signature_path) {
            abort(404);
        }

        $path = storage_path('app/public/' . $director->signature_path);
        if (!is_readable($path)) {
            abort(404);
        }

        $mimeType = mime_content_type($path) ?: 'image/png';
        return response()->file($path, ['Content-Type' => $mimeType]);
    }

    /**
     * Return a signed HTTPS-friendly URL for the director's signature (works in production).
     */
    private function signatureUrlFor(Director $director): ?string
    {
        if (!$director->signature_path) {
            return null;
        }
        return URL::temporarySignedRoute(
            'api.directors.signature.image',
            now()->addHours(24),
            ['director' => $director->id]
        );
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
                'signature_url' => $this->signatureUrlFor($director),
            ],
        ]);
    }
}

