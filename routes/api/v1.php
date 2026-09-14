<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendeeController;
use App\Http\Controllers\Api\V1\AttendeeDashboardController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChurchController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventRegistrationController;
use App\Http\Controllers\Api\V1\EventSessionController;
use App\Http\Controllers\Api\V1\MissionController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PublicEventController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SystemLogController;
use App\Http\Controllers\Api\V1\UnionController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/sso/{provider}', [AuthController::class, 'sso']);
    Route::get('auth/invite/{token}', [AuthController::class, 'showInvite']);
    Route::post('auth/accept-invite', [AuthController::class, 'acceptInvite']);
});

Route::middleware('throttle:6,1')->group(function () {
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
});

Route::get('public/event/{eventInviteToken}', [PublicEventController::class, 'show']);
Route::post('public/event/{eventInviteToken}/register', [PublicEventController::class, 'register'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::middleware('throttle:6,1')->post('auth/email/verification-notification', [AuthController::class, 'resendVerification']);
    Route::get('attendee/dashboard', [AttendeeDashboardController::class, 'index']);
    Route::get('attendee/registrations', [AttendeeDashboardController::class, 'registrations']);
    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::get('reports/dashboard', [ReportController::class, 'globalDashboard']);

    Route::get('download/id-card-template', function () {
        $filePath = storage_path('id-card-formats/format1.jpg');
        if (!file_exists($filePath)) {
            abort(404, 'ID card template not found.');
        }

        return response()->download($filePath, 'id_card_template.jpg', [
            'Content-Type' => 'image/jpeg',
        ]);
    });

    Route::apiResource('organizations', OrganizationController::class);

    Route::post('users/invite', [UserController::class, 'invite']);
    Route::put('users/{user}/event-access', [UserController::class, 'syncEventAccess']);
    Route::post('users/{user}/photo', [UserController::class, 'uploadPhoto']);
    Route::delete('users/{user}/photo', [UserController::class, 'removePhoto']);
    Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite']);
    Route::delete('users/{user}/cancel-invite', [UserController::class, 'cancelInvite']);
    Route::apiResource('users', UserController::class);

    Route::apiResource('unions', UnionController::class);
    Route::apiResource('missions', MissionController::class);
    Route::apiResource('churches', ChurchController::class);

    Route::get('attendees/check-duplicates', [AttendeeController::class, 'checkDuplicates']);
    Route::apiResource('attendees', AttendeeController::class);
    Route::post('attendees/{attendee}/photo', [AttendeeController::class, 'uploadPhoto']);
    Route::delete('attendees/{attendee}/photo', [AttendeeController::class, 'removePhoto']);

    Route::apiResource('events', EventController::class);

    Route::scopeBindings()->group(function () {
        Route::post('events/{event}/id-card-background', [EventController::class, 'uploadIdCardBackground']);
        Route::delete('events/{event}/id-card-background', [EventController::class, 'removeIdCardBackground']);
        Route::post('events/{event}/banner', [EventController::class, 'uploadBanner']);
        Route::delete('events/{event}/banner', [EventController::class, 'removeBanner']);

        Route::apiResource('events.sessions', EventSessionController::class);

        Route::get('events/{event}/registrations/qr-export', [EventRegistrationController::class, 'exportQr']);
        Route::get('events/{event}/registrations/id-cards/bulk-download', [EventRegistrationController::class, 'bulkDownloadIdCards']);
        Route::get('events/{event}/registrations/id-cards/download-all', [EventRegistrationController::class, 'downloadAllIdCards']);
        Route::post('events/{event}/registrations/id-cards/grid-download', [EventRegistrationController::class, 'startIdCardGridDownload']);
        Route::post('events/{event}/registrations/id-cards/grid-download-all', [EventRegistrationController::class, 'startIdCardGridDownloadAll']);
        Route::get('events/{event}/registrations/id-cards/grid-download/{gridDownload}', [EventRegistrationController::class, 'showIdCardGridDownload'])->name('events.registrations.id-card-grid-download');
        Route::get('events/{event}/registrations/id-cards/grid-download/{gridDownload}/file', [EventRegistrationController::class, 'downloadIdCardGridDownload'])->name('events.registrations.id-card-grid-download-file');
        Route::apiResource('events.registrations', EventRegistrationController::class)
            ->only(['index', 'store', 'show', 'destroy']);
        Route::get('events/{event}/registrations/{registration}/id-card', [EventRegistrationController::class, 'downloadIdCard']);
        Route::get('events/{event}/registrations/{registration}/id-card-image', [EventRegistrationController::class, 'getIdCardImage'])->name('events.registrations.id-card-image');
        Route::post('events/{event}/registrations/{registration}/id-card/regenerate', [EventRegistrationController::class, 'regenerateIdCard']);

        Route::get('events/{event}/sessions/{session}/attendance', [AttendanceController::class, 'forSession']);
        Route::get('events/{event}/sessions/{session}/roster', [AttendanceController::class, 'roster']);
        Route::get('events/{event}/sessions/{session}/quick-stats', [ReportController::class, 'eventSessionQuickStats']);

        Route::get('events/{event}/reports/attendance-summary', [ReportController::class, 'eventAttendanceSummary']);
        Route::get('events/{event}/reports/attendance-summary/export', [ReportController::class, 'exportEventAttendance']);
    });

    Route::post('attendance/scan', [AttendanceController::class, 'scan']);
    Route::post('attendance/manual', [AttendanceController::class, 'manual']);
    Route::post('attendance/{attendanceRecord}/check-out', [AttendanceController::class, 'checkOut']);
    Route::post('attendance/sync-batch', [AttendanceController::class, 'syncBatch']);

    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::middleware('role:super_admin')->group(function () {
        Route::get('system-logs/dates', [SystemLogController::class, 'dates']);
        Route::get('system-logs', [SystemLogController::class, 'index']);
    });

    Route::get('organizations/{organization}/reports/dashboard', [ReportController::class, 'organizationDashboard']);
    Route::get('organizations/{organization}/reports/attendance-overview', [ReportController::class, 'organizationAttendanceOverview']);
});
