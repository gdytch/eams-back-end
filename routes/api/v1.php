<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendeeController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventRegistrationController;
use App\Http\Controllers\Api\V1\EventSessionController;
use App\Http\Controllers\Api\V1\MissionController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\UnionController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::apiResource('organizations', OrganizationController::class);
    Route::post('organizations/{organization}/id-card-background', [OrganizationController::class, 'uploadIdCardBackground']);
    Route::delete('organizations/{organization}/id-card-background', [OrganizationController::class, 'removeIdCardBackground']);

    Route::put('users/{user}/event-access', [UserController::class, 'syncEventAccess']);
    Route::apiResource('users', UserController::class);

    Route::apiResource('unions', UnionController::class);
    Route::apiResource('missions', MissionController::class);

    Route::get('attendees/check-duplicates', [AttendeeController::class, 'checkDuplicates']);
    Route::apiResource('attendees', AttendeeController::class);

    Route::apiResource('events', EventController::class);

    Route::scopeBindings()->group(function () {
        Route::apiResource('events.sessions', EventSessionController::class);

        Route::get('events/{event}/registrations/qr-export', [EventRegistrationController::class, 'exportQr']);
        Route::apiResource('events.registrations', EventRegistrationController::class)
            ->only(['index', 'store', 'show', 'destroy']);
        Route::get('events/{event}/registrations/{registration}/id-card', [EventRegistrationController::class, 'downloadIdCard']);
        Route::post('events/{event}/registrations/{registration}/id-card/regenerate', [EventRegistrationController::class, 'regenerateIdCard']);

        Route::get('events/{event}/sessions/{session}/attendance', [AttendanceController::class, 'forSession']);
    });

    Route::post('attendance/scan', [AttendanceController::class, 'scan']);
    Route::post('attendance/manual', [AttendanceController::class, 'manual']);
    Route::post('attendance/{attendanceRecord}/check-out', [AttendanceController::class, 'checkOut']);

    Route::get('audit-logs', [AuditLogController::class, 'index']);
});
