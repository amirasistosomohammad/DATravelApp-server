<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IctAdminSettingsController;
use App\Http\Controllers\PersonnelManagementController;
use App\Http\Controllers\DirectorManagementController;
use App\Http\Controllers\TimeLoggingController;
use App\Http\Controllers\TravelOrderController;
use App\Http\Controllers\DirectorTravelOrderController;
use App\Http\Controllers\DirectorProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::get('/branding', [IctAdminSettingsController::class, 'getBrandingPublic']);

// Public avatar serving (no auth required for images)
Route::get('/personnel-avatar/{filename}', [PersonnelManagementController::class, 'getAvatar']);
Route::get('/director-avatar/{filename}', [DirectorManagementController::class, 'getAvatar']);

// Director signature image (signed URL; correct Content-Type, works over HTTPS in production)
Route::get('/directors/profile/signature/image', [DirectorProfileController::class, 'serveSignatureImage'])
    ->name('api.directors.signature.image')
    ->middleware('signed');

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

    // Personnel - Travel orders (Phase 4 & 5)
    Route::get('/personnel/travel-orders', [TravelOrderController::class, 'index']);
    Route::post('/personnel/travel-orders', [TravelOrderController::class, 'store']);
    Route::get('/personnel/travel-orders/{travelOrder}', [TravelOrderController::class, 'show']);
    Route::get('/personnel/travel-orders/{travelOrder}/export/pdf', [TravelOrderController::class, 'exportPdf']);
    Route::put('/personnel/travel-orders/{travelOrder}', [TravelOrderController::class, 'update']);
    Route::delete('/personnel/travel-orders/{travelOrder}', [TravelOrderController::class, 'destroy']);
    Route::post('/personnel/travel-orders/{travelOrder}/submit', [TravelOrderController::class, 'submit']);
    Route::get('/personnel/directors', [TravelOrderController::class, 'availableDirectors']);
    Route::get('/personnel/travel-order-attachments/{attachment}/download', [TravelOrderController::class, 'downloadAttachment'])
        ->where('attachment', '[0-9]+');

    // Director - Travel order reviews (Phase 5)
    Route::get('/directors/travel-orders/pending', [DirectorTravelOrderController::class, 'pending']);
    Route::get('/directors/travel-orders/history', [DirectorTravelOrderController::class, 'history']);
    Route::get('/directors/travel-orders/{travelOrder}', [DirectorTravelOrderController::class, 'show']);
    Route::post('/directors/travel-orders/{travelOrder}/action', [DirectorTravelOrderController::class, 'action']);
    Route::get('/directors/travel-order-attachments/{attachment}/download', [DirectorTravelOrderController::class, 'downloadAttachment'])
        ->where('attachment', '[0-9]+');

    // Director - self-service profile (signature)
    Route::get('/directors/profile/signature', [DirectorProfileController::class, 'getSignature']);
    Route::post('/directors/profile/signature', [DirectorProfileController::class, 'updateSignature']);
});
