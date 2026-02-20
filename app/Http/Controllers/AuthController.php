<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\IctAdmin;
use App\Models\Personnel;
use App\Models\Director;
use App\Models\TimeLog;
use App\Models\TravelOrderApproval;

class AuthController extends Controller
{
    /**
     * Login user and return token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // Rate limiting: max 5 attempts per minute per IP
        $key = 'login.'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Please try again in '.$seconds.' seconds.',
            ], 429);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            RateLimiter::hit($key);
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid username and password.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $username = $request->username;
        $password = $request->password;

        // Try to authenticate user using Laravel's auth system
        $user = null;
        $userType = null;
        $guard = null;

        // Check ICT Admin using auth guard
        if (Auth::guard('ict_admin')->attempt(['username' => $username, 'password' => $password])) {
            $user = Auth::guard('ict_admin')->user();
            if (!$user->is_active) {
                Auth::guard('ict_admin')->logout();
                RateLimiter::hit($key);
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact administrator.',
                ], 403);
            }
            $userType = 'ict_admin';
            $guard = 'ict_admin';
        }
        // Check Personnel using auth guard
        elseif (Auth::guard('personnel')->attempt(['username' => $username, 'password' => $password])) {
            $user = Auth::guard('personnel')->user();
            if (!$user->is_active) {
                Auth::guard('personnel')->logout();
                RateLimiter::hit($key);
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact administrator.',
                    'reason_for_deactivation' => $user->reason_for_deactivation ?? null,
                ], 403);
            }
            $userType = 'personnel';
            $guard = 'personnel';
        }
        // Check Director using auth guard
        elseif (Auth::guard('director')->attempt(['username' => $username, 'password' => $password])) {
            $user = Auth::guard('director')->user();
            if (!$user->is_active) {
                Auth::guard('director')->logout();
                RateLimiter::hit($key);
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact administrator.',
                    'reason_for_deactivation' => $user->reason_for_deactivation ?? null,
                ], 403);
            }
            $userType = 'director';
            $guard = 'director';
        }

        // If no user found or password incorrect
        if (!$user) {
            RateLimiter::hit($key);
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials. Please check your username and password.',
            ], 401);
        }

        // Clear rate limiter on successful login
        RateLimiter::clear($key);

        // Logout from session (we're using token-based auth, not session)
        Auth::guard($guard)->logout();

        // Create token with 12-hour expiration (configured in sanctum.php)
        /** @var \App\Models\IctAdmin|\App\Models\Personnel|\App\Models\Director $user */
        $token = $user->createToken('auth-token');

        // Record time-in for personnel/director logins
        if ($user instanceof Personnel || $user instanceof Director) {
            $tz = config('app.timezone') ?: 'Asia/Manila';
            $now = now($tz);
            $logData = [
                'log_date' => $now->toDateString(),
                'time_in' => $now->format('H:i'),
            ];

            if ($user instanceof Personnel) {
                $logData['personnel_id'] = $user->id;
                $logData['director_id'] = null;
            } else {
                $logData['director_id'] = $user->id;
                $logData['personnel_id'] = null;
            }

            TimeLog::create($logData);
        }

        // Prepare user data for response
        $userData = [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $userType,
            'position' => $user->position ?? null,
            'department' => $user->department ?? 'Department of Agriculture',
        ];

        // For directors, check if they have recommend role
        if ($user instanceof Director) {
            $userData['has_recommend_role'] = TravelOrderApproval::query()
                ->where('director_id', $user->id)
                ->where('step_order', 1)
                ->exists();
        }

        // Avatar URL: full URL from internet (e.g. seeded) or API path
        if ($user instanceof Personnel && $user->avatar_path) {
            $userData['avatar'] = str_starts_with($user->avatar_path, 'http')
                ? $user->avatar_path
                : url('/storage/' . ltrim($user->avatar_path, '/'));
        } elseif ($user instanceof Director && $user->avatar_path) {
            $userData['avatar'] = str_starts_with($user->avatar_path, 'http')
                ? $user->avatar_path
                : url('/storage/' . ltrim($user->avatar_path, '/'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $userData,
                'token' => $token->plainTextToken,
                'expires_at' => now()->addHours(12)->toIso8601String(),
            ],
        ], 200);
    }

    /**
     * Get authenticated user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        // Get authenticated user from Sanctum token
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Determine user type based on model class
        $userType = null;
        if ($user instanceof IctAdmin) {
            $userType = 'ict_admin';
        } elseif ($user instanceof Personnel) {
            $userType = 'personnel';
        } elseif ($user instanceof Director) {
            $userType = 'director';
        }

        $userData = [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $userType,
            'position' => $user->position ?? null,
            'department' => $user->department ?? 'Department of Agriculture',
        ];

        // For directors, check if they have recommend role
        if ($user instanceof Director) {
            $userData['has_recommend_role'] = TravelOrderApproval::query()
                ->where('director_id', $user->id)
                ->where('step_order', 1)
                ->exists();
        }

        // Avatar URL: full URL from internet (e.g. seeded) or API path
        if ($user instanceof Personnel && $user->avatar_path) {
            $userData['avatar'] = str_starts_with($user->avatar_path, 'http')
                ? $user->avatar_path
                : url('/storage/' . ltrim($user->avatar_path, '/'));
        } elseif ($user instanceof Director && $user->avatar_path) {
            $userData['avatar'] = str_starts_with($user->avatar_path, 'http')
                ? $user->avatar_path
                : url('/storage/' . ltrim($user->avatar_path, '/'));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $userData,
            ],
        ], 200);
    }

    /**
     * Logout user (revoke token)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user instanceof Personnel || $user instanceof Director) {
            $tz = config('app.timezone') ?: 'Asia/Manila';
            $now = now($tz);
            $query = TimeLog::query()
                ->whereNull('time_out')
                ->orderByDesc('log_date')
                ->orderByDesc('time_in');

            if ($user instanceof Personnel) {
                $query->where('personnel_id', $user->id);
            } else {
                $query->where('director_id', $user->id);
            }

            $openLog = $query->first();
            if ($openLog) {
                $openLog->time_out = $now->format('H:i');
                $openLog->save();
            }
        }

        if ($user) {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * Logout from all devices (revoke all tokens)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logoutAll(Request $request)
    {
        $user = $request->user();

        if ($user instanceof Personnel || $user instanceof Director) {
            $tz = config('app.timezone') ?: 'Asia/Manila';
            $now = now($tz);
            $query = TimeLog::query()
                ->whereNull('time_out')
                ->orderByDesc('log_date')
                ->orderByDesc('time_in');

            if ($user instanceof Personnel) {
                $query->where('personnel_id', $user->id);
            } else {
                $query->where('director_id', $user->id);
            }

            $openLog = $query->first();
            if ($openLog) {
                $openLog->time_out = $now->format('H:i');
                $openLog->save();
            }
        }

        if ($user) {
            // Revoke all tokens for this user
            $user->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out from all devices successfully',
        ], 200);
    }
}

