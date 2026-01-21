<?php

namespace App\Services;

use App\Models\IctAdmin;
use App\Models\Personnel;
use App\Models\Director;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /**
     * Get the authenticated user from token
     *
     * @param string $token
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public static function getUserFromToken($token)
    {
        if (!$token) {
            return null;
        }

        try {
            // Find the token in database using Sanctum's method
            $accessToken = PersonalAccessToken::findToken($token);
            
            if (!$accessToken) {
                return null;
            }

            // Check if token is expired
            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                return null;
            }

            // Get the tokenable model (the user)
            $tokenable = $accessToken->tokenable;

            // Return the user model
            return $tokenable;
        } catch (\Exception $e) {
            // Log error and return null
            \Log::error('AuthService::getUserFromToken error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user type from model instance
     *
     * @param mixed $user
     * @return string|null
     */
    public static function getUserType($user)
    {
        if ($user instanceof IctAdmin) {
            return 'ict_admin';
        } elseif ($user instanceof Personnel) {
            return 'personnel';
        } elseif ($user instanceof Director) {
            return 'director';
        }

        return null;
    }
}

