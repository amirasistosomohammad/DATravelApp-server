<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IctAdminSettingsController;
use App\Http\Controllers\PersonnelManagementController;
use App\Http\Controllers\DirectorManagementController;
use App\Http\Controllers\TimeLoggingController;
use App\Http\Controllers\TravelOrderController;
use App\Http\Controllers\DirectorTravelOrderController;
use App\Http\Controllers\DirectorProfileController;
use App\Http\Controllers\PersonnelProfileController;
use App\Http\Controllers\ReportsController;
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

    // Departments list (for filter dropdowns; any authenticated user)
    Route::get('/departments', [TravelOrderController::class, 'listDepartments']);

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

    // ICT Admin - Reports & Analytics
    Route::get('/ict-admin/reports/analytics', [ReportsController::class, 'analytics']);

    // ICT Admin - Travel orders (view all)
    Route::get('/ict-admin/travel-orders', [TravelOrderController::class, 'indexForAdmin']);
    Route::get('/ict-admin/travel-orders/{travelOrder}', [TravelOrderController::class, 'showForAdmin']);
    Route::get('/ict-admin/travel-orders/{travelOrder}/export/pdf', [TravelOrderController::class, 'exportPdfForAdmin']);
    Route::get('/ict-admin/travel-orders/{travelOrder}/export/excel', [TravelOrderController::class, 'exportExcelForAdmin']);
    Route::get('/ict-admin/travel-order-attachments/{attachment}/download', [TravelOrderController::class, 'downloadAttachmentForAdmin'])
        ->where('attachment', '[0-9]+');

    // Personnel - Profile (view own details)
    Route::get('/personnel/profile', [PersonnelProfileController::class, 'show']);

    // Personnel - Travel orders (Phase 4 & 5)
    Route::get('/personnel/travel-orders', [TravelOrderController::class, 'index']);
    // History and calendar MUST be defined before any {travelOrder} bindings
    Route::get('/personnel/travel-orders/history', [TravelOrderController::class, 'history']);
    Route::get('/personnel/travel-orders/calendar', [TravelOrderController::class, 'calendar']);
    // All personnel TOs (coworkers view) - must be before {travelOrder}
    Route::get('/personnel/travel-orders/all/attachments/{attachment}/download', [TravelOrderController::class, 'downloadAttachmentForPersonnelPeer'])
        ->where('attachment', '[0-9]+');
    Route::get('/personnel/travel-orders/all', [TravelOrderController::class, 'indexAllPersonnel']);
    Route::get('/personnel/travel-orders/all/{travelOrder}', [TravelOrderController::class, 'showAllPersonnel']);
    Route::post('/personnel/travel-orders', [TravelOrderController::class, 'store']);
    Route::get('/personnel/travel-orders/{travelOrder}', [TravelOrderController::class, 'show']);
    Route::get('/personnel/travel-orders/{travelOrder}/export/pdf', [TravelOrderController::class, 'exportPdf']);
    Route::get('/personnel/travel-orders/{travelOrder}/export/excel', [TravelOrderController::class, 'exportExcel']);
    Route::put('/personnel/travel-orders/{travelOrder}', [TravelOrderController::class, 'update']);
    Route::delete('/personnel/travel-orders/{travelOrder}', [TravelOrderController::class, 'destroy']);
    Route::post('/personnel/travel-orders/{travelOrder}/submit', [TravelOrderController::class, 'submit']);
    Route::post('/personnel/travel-orders/{travelOrder}/cancel', [TravelOrderController::class, 'cancel']);
    // Travel order editors (invite / remove) - must be before generic {travelOrder}
    Route::get('/personnel/travel-orders/{travelOrder}/editors/list-personnel', [TravelOrderController::class, 'listPersonnelForInvite']);
    Route::get('/personnel/travel-orders/{travelOrder}/editors', [TravelOrderController::class, 'indexEditors']);
    Route::post('/personnel/travel-orders/{travelOrder}/editors', [TravelOrderController::class, 'storeEditor']);
    Route::delete('/personnel/travel-orders/{travelOrder}/editors/{editorPersonnel}', [TravelOrderController::class, 'destroyEditor']);
    Route::get('/personnel/directors', [TravelOrderController::class, 'availableDirectors']);
    Route::get('/personnel/travel-order-attachments/{attachment}/download', [TravelOrderController::class, 'downloadAttachment'])
        ->where('attachment', '[0-9]+');

    // Director - Travel order reviews (Phase 5)
    Route::get('/directors/travel-orders/pending', [DirectorTravelOrderController::class, 'pending']);
    Route::get('/directors/travel-orders/history', [DirectorTravelOrderController::class, 'history']);
    Route::get('/directors/travel-orders/{travelOrder}', [DirectorTravelOrderController::class, 'show']);
    Route::get('/directors/travel-orders/{travelOrder}/export/excel', [DirectorTravelOrderController::class, 'exportExcel']);
    Route::post('/directors/travel-orders/{travelOrder}/action', [DirectorTravelOrderController::class, 'action']);
    Route::get('/directors/travel-order-attachments/{attachment}/download', [DirectorTravelOrderController::class, 'downloadAttachment'])
        ->where('attachment', '[0-9]+');

    // Director - self-service profile
    Route::get('/directors/profile', [DirectorProfileController::class, 'show']);
    Route::get('/directors/profile/signature', [DirectorProfileController::class, 'getSignature']);
    Route::post('/directors/profile/signature', [DirectorProfileController::class, 'updateSignature']);
});
