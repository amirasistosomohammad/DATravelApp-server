<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IctAdminSettingsController;
use App\Http\Controllers\PersonnelManagementController;
use App\Http\Controllers\DirectorManagementController;
use App\Http\Controllers\TimeLoggingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::get('/branding', [IctAdminSettingsController::class, 'getBrandingPublic']);

// Public avatar serving (no auth required for images)
Route::get('/personnel-avatar/{filename}', [PersonnelManagementController::class, 'getAvatar']);
Route::get('/director-avatar/{filename}', [DirectorManagementController::class, 'getAvatar']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    // ICT Admin - Personnel management
    Route::get('/ict-admin/personnel', [PersonnelManagementController::class, 'index']);
    Route::post('/ict-admin/personnel', [PersonnelManagementController::class, 'store']);
    Route::put('/ict-admin/personnel/{personnel}', [PersonnelManagementController::class, 'update']);
    Route::delete('/ict-admin/personnel/{personnel}', [PersonnelManagementController::class, 'destroy']);

    // ICT Admin - Director management
    Route::get('/ict-admin/directors', [DirectorManagementController::class, 'index']);
    Route::post('/ict-admin/directors', [DirectorManagementController::class, 'store']);
    Route::put('/ict-admin/directors/{director}', [DirectorManagementController::class, 'update']);
    Route::delete('/ict-admin/directors/{director}', [DirectorManagementController::class, 'destroy']);

    // ICT Admin - Time logging
    Route::get('/ict-admin/time-logs', [TimeLoggingController::class, 'index']);
    Route::post('/ict-admin/time-logs', [TimeLoggingController::class, 'store']);
    Route::put('/ict-admin/time-logs/{timeLog}', [TimeLoggingController::class, 'update']);
    Route::delete('/ict-admin/time-logs/{timeLog}', [TimeLoggingController::class, 'destroy']);

    // ICT Admin - System settings (change password, branding)
    Route::put('/ict-admin/change-password', [IctAdminSettingsController::class, 'changePassword']);
    Route::get('/ict-admin/branding', [IctAdminSettingsController::class, 'getBranding']);
    Route::post('/ict-admin/branding', [IctAdminSettingsController::class, 'updateBranding']);
});
