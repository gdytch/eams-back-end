<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendeeController;
use App\Http\Controllers\Api\V1\AttendeeDashboardController;
use App\Http\Controllers\Api\V1\AttendeeQrClaimController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BreakoutController;
use App\Http\Controllers\Api\V1\BreakoutProgramController;
use App\Http\Controllers\Api\V1\CertificateController;
use App\Http\Controllers\Api\V1\CertificateRunController;
use App\Http\Controllers\Api\V1\ChurchController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventAttendeeController;
use App\Http\Controllers\Api\V1\EventBreakoutPickController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventProgramBuilderController;
use App\Http\Controllers\Api\V1\EventProgramController;
use App\Http\Controllers\Api\V1\EventProgramItemController;
use App\Http\Controllers\Api\V1\EventSessionController;
use App\Http\Controllers\Api\V1\FormAnswerFileController;
use App\Http\Controllers\Api\V1\FormController;
use App\Http\Controllers\Api\V1\GlobalSearchController;
use App\Http\Controllers\Api\V1\MissionController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProfilePhotoController;
use App\Http\Controllers\Api\V1\PublicChurchController;
use App\Http\Controllers\Api\V1\PublicEventController;
use App\Http\Controllers\Api\V1\PublicEventProgramController;
use App\Http\Controllers\Api\V1\PublicEventRegistrationController;
use App\Http\Controllers\Api\V1\PublicMissionController;
use App\Http\Controllers\Api\V1\PublicUnionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SpeakerController;
use App\Http\Controllers\Api\V1\SystemLogController;
use App\Http\Controllers\Api\V1\UnionController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('profile-photos/{kind}/{id}/{size}', ProfilePhotoController::class)
    ->middleware('signed')->whereNumber('id')->whereIn('kind', ['users', 'attendees'])
    ->whereIn('size', ['sm', 'md', 'lg', 'original'])->name('profile-photos.show');

Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/sso/{provider}', [AuthController::class, 'sso']);
    Route::get('auth/invite/{token}', [AuthController::class, 'showInvite']);
    Route::post('auth/accept-invite', [AuthController::class, 'acceptInvite']);
});

Route::prefix('public/attendee-claims')->middleware('throttle:10,1')->group(function () {
    Route::post('status', [AttendeeQrClaimController::class, 'status']);
    Route::post('verify', [AttendeeQrClaimController::class, 'verify']);
});

Route::prefix('public/attendee-claims')->middleware('throttle:6,1')->group(function () {
    Route::post('email-code', [AttendeeQrClaimController::class, 'sendEmailCode']);
    Route::post('verify-email-code', [AttendeeQrClaimController::class, 'verifyEmailCode']);
    Route::post('complete', [AttendeeQrClaimController::class, 'complete']);
});

Route::middleware('throttle:6,1')->group(function () {
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
});

Route::get('public/event/{eventInviteToken}', [PublicEventController::class, 'show']);
Route::get('public/programs/{publicSlug}', [PublicEventProgramController::class, 'show']);
Route::middleware('auth:sanctum')->prefix('public/event/{eventInviteToken}/registration')->group(function () {
    Route::get('/', [PublicEventRegistrationController::class, 'show']);
    Route::delete('/', [PublicEventRegistrationController::class, 'cancel']);
    Route::get('account-status', [PublicEventRegistrationController::class, 'accountStatus']);
    Route::put('basic-info', [PublicEventRegistrationController::class, 'saveBasicInfo']);
    Route::put('territory', [PublicEventRegistrationController::class, 'saveTerritory']);
    Route::put('breakouts', [PublicEventRegistrationController::class, 'saveBreakouts']);
    Route::get('forms', [PublicEventRegistrationController::class, 'forms']);
    Route::post('forms/{form}', [PublicEventRegistrationController::class, 'saveForm'])->whereNumber('form')->middleware('throttle:60,1');
    Route::post('forms/{form}/skip', [PublicEventRegistrationController::class, 'skipForm'])->whereNumber('form');
    Route::post('finish', [PublicEventRegistrationController::class, 'finish']);
});
Route::get('public/unions', [PublicUnionController::class, 'index']);
Route::get('public/missions', [PublicMissionController::class, 'index']);
Route::get('public/churches', [PublicChurchController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('search', GlobalSearchController::class)->middleware('throttle:60,1');
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::middleware('throttle:6,1')->post('auth/email/verification-notification', [AuthController::class, 'resendVerification']);
    Route::get('attendee/dashboard', [AttendeeDashboardController::class, 'index']);
    Route::get('attendee/registrations', [AttendeeDashboardController::class, 'registrations']);
    Route::get('attendee/events/{event}/attendance', [AttendeeDashboardController::class, 'attendance']);
    Route::get('attendee/events/{event}/program', [AttendeeDashboardController::class, 'program']);
    Route::get('attendee/events/{eventId}/speakers', [AttendeeDashboardController::class, 'speakers'])->whereNumber('eventId');
    Route::get('form-answers/{answer}/file', FormAnswerFileController::class)->middleware('throttle:60,1')->name('form-answers.file');
    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::get('reports/dashboard', [ReportController::class, 'globalDashboard']);

    Route::get('download/id-card-template', function () {
        $filePath = storage_path('id-card-formats/format1.jpg');
        if (! file_exists($filePath)) {
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
    Route::post('churches/{church}/verify', [ChurchController::class, 'verify']);
    Route::post('churches/{church}/merge', [ChurchController::class, 'merge']);
    Route::apiResource('churches', ChurchController::class);

    Route::get('attendees/check-duplicates', [AttendeeController::class, 'checkDuplicates']);
    Route::get('attendees/duplicates', [AttendeeController::class, 'duplicates']);
    Route::post('attendees/duplicates/merge', [AttendeeController::class, 'mergeDuplicates']);
    Route::post('attendees/duplicates/dismiss', [AttendeeController::class, 'dismissDuplicates']);
    Route::get('attendees/export', [AttendeeController::class, 'export'])->middleware('throttle:6,1');
    Route::get('attendees/{attendeeId}', [AttendeeController::class, 'showById'])->whereNumber('attendeeId');
    Route::apiResource('attendees', AttendeeController::class)->except('show');
    Route::put('attendees/{attendee}/breakouts', [AttendeeController::class, 'saveBreakouts']);
    Route::get('attendees/{attendee}/forms', [AttendeeController::class, 'forms']);
    Route::post('attendees/{attendee}/forms/{form}', [AttendeeController::class, 'saveForm'])->whereNumber('form')->middleware('throttle:60,1');
    Route::post('attendees/{attendee}/forms/{form}/skip', [AttendeeController::class, 'skipForm'])->whereNumber('form');
    Route::post('attendees/{attendee}/finish', [AttendeeController::class, 'finish']);
    Route::post('attendees/{attendee}/resend-invite', [AttendeeController::class, 'resendInvite']);
    Route::post('attendees/{attendee}/photo', [AttendeeController::class, 'uploadPhoto']);
    Route::delete('attendees/{attendee}/photo', [AttendeeController::class, 'removePhoto']);

    Route::apiResource('events', EventController::class)->except('destroy');
    Route::post('events/{event}/archive', [EventController::class, 'archive']);
    Route::post('events/{event}/unarchive', [EventController::class, 'unarchive']);

    Route::scopeBindings()->group(function () {
        Route::post('events/{event}/id-card-background', [EventController::class, 'uploadIdCardBackground']);
        Route::delete('events/{event}/id-card-background', [EventController::class, 'removeIdCardBackground']);
        Route::post('events/{event}/banner', [EventController::class, 'uploadBanner']);
        Route::delete('events/{event}/banner', [EventController::class, 'removeBanner']);
        Route::put('events/{event}/speaker-settings', [SpeakerController::class, 'updateSettings']);
        Route::post('events/{event}/speakers/{speaker}/photo', [SpeakerController::class, 'uploadPhoto']);
        Route::apiResource('events.speakers', SpeakerController::class)->except(['show']);

        Route::apiResource('events.sessions', EventSessionController::class);

        Route::put('events/{event}/breakout-picks', [EventBreakoutPickController::class, 'update']);
        Route::apiResource('events.breakouts', BreakoutController::class)->scoped();
        Route::post('events/{event}/breakouts/{breakout}/program', [BreakoutProgramController::class, 'store'])->scopeBindings();
        Route::get('events/{event}/breakouts/{breakout}/program', [BreakoutProgramController::class, 'show'])->scopeBindings();
        Route::delete('events/{event}/breakouts/{breakout}/program', [BreakoutProgramController::class, 'destroy'])->scopeBindings();

        Route::post('events/{event}/certificates/{certificate}/template', [CertificateController::class, 'uploadTemplate']);
        Route::get('events/{event}/certificates/{certificate}/audience-count', [CertificateController::class, 'audienceCount'])->scopeBindings();
        Route::post('events/{event}/certificates/{certificate}/publish', [CertificateController::class, 'publish'])->scopeBindings();
        Route::post('events/{event}/certificates/{certificate}/unpublish', [CertificateController::class, 'unpublish'])->scopeBindings();
        Route::get('events/{event}/certificates/{certificate}/attendees/{attendee}/download', [CertificateController::class, 'download'])->whereNumber('attendee')->scopeBindings();
        Route::get('events/{event}/certificates/{certificate}/status', [CertificateRunController::class, 'status'])->scopeBindings();
        Route::post('events/{event}/certificates/{certificate}/retry-failed', [CertificateRunController::class, 'retryFailed'])->scopeBindings();
        Route::post('events/{event}/certificates/{certificate}/attendees/{attendee}/regenerate', [CertificateRunController::class, 'regenerate'])->whereNumber('attendee')->scopeBindings();
        Route::get('events/{event}/certificates/{certificate}/download-all', [CertificateRunController::class, 'downloadAll'])->scopeBindings();
        Route::post('events/{event}/certificates/{certificate}/google-font', [CertificateController::class, 'setGoogleFont']);
        Route::apiResource('events.certificates', CertificateController::class)->scoped();

        Route::put('events/{event}/forms/order', [FormController::class, 'order']);
        Route::post('events/{event}/forms/copy', [FormController::class, 'copy']);
        Route::get('events/{event}/forms/export', [FormController::class, 'export'])->middleware('throttle:6,1');
        Route::apiResource('events.forms', FormController::class)->scoped();
        Route::get('events/{event}/forms/{form}/answers', [FormController::class, 'answers'])->scopeBindings()->middleware('throttle:60,1')->name('forms.answers');
        Route::post('events/{event}/forms/{form}/publish', [FormController::class, 'publish'])->scopeBindings();
        Route::post('events/{event}/forms/{form}/unpublish', [FormController::class, 'unpublish'])->scopeBindings();

        Route::put('events/{event}/program', [EventProgramBuilderController::class, 'metadata']);
        // The breakout program has one fixed day, so it only exposes sections and parts.
        foreach (['events/{event}/program/builder/{kind}' => ['days', 'sections', 'parts'], 'events/{event}/breakouts/{breakout}/program/builder/{kind}' => ['sections', 'parts']] as $prefix => $kinds) {
            Route::prefix($prefix)->whereIn('kind', $kinds)->scopeBindings()->group(function () {
                $controller = EventProgramBuilderController::class;
                Route::post('/', [$controller, 'store']);
                Route::put('order', [$controller, 'reorder']);
                Route::put('{node}', [$controller, 'update'])->whereNumber('node');
                Route::delete('{node}', [$controller, 'destroy'])->whereNumber('node');
                Route::post('{node}/duplicate', [$controller, 'duplicate'])->whereNumber('node');
            });
        }

        Route::post('events/{event}/program', [EventProgramController::class, 'store']);
        Route::get('events/{event}/program', [EventProgramController::class, 'show']);
        Route::delete('events/{event}/program', [EventProgramController::class, 'destroy']);

        Route::get('events/{event}/program/items', [EventProgramItemController::class, 'index']);
        Route::post('events/{event}/program/items', [EventProgramItemController::class, 'store']);
        Route::post('events/{event}/program/items/reorder', [EventProgramItemController::class, 'reorder']);
        Route::get('events/{event}/program/items/{itemId}', [EventProgramItemController::class, 'show']);
        Route::put('events/{event}/program/items/{itemId}', [EventProgramItemController::class, 'update']);
        Route::delete('events/{event}/program/items/{itemId}', [EventProgramItemController::class, 'destroy']);
        Route::post('events/{event}/program/items/{itemId}/photos', [EventProgramItemController::class, 'uploadPhoto']);
        Route::delete('events/{event}/program/items/{itemId}/photos', [EventProgramItemController::class, 'removePhoto']);

        Route::get('events/{event}/registrations/qr-export', [EventAttendeeController::class, 'exportQr'])->middleware('throttle:6,1');
        Route::get('events/{event}/registrations/id-cards/bulk-download', [EventAttendeeController::class, 'bulkDownloadIdCards'])->middleware('throttle:60,1,id-card-download:');
        Route::get('events/{event}/registrations/id-cards/download-all', [EventAttendeeController::class, 'downloadAllIdCards'])->middleware('throttle:60,1,id-card-download:');
        Route::post('events/{event}/registrations/id-cards/grid-download', [EventAttendeeController::class, 'startIdCardGridDownload'])->middleware('throttle:60,1,id-card-grid-start:');
        Route::post('events/{event}/registrations/id-cards/grid-download-all', [EventAttendeeController::class, 'startIdCardGridDownloadAll'])->middleware('throttle:60,1,id-card-grid-start:');
        Route::get('events/{event}/registrations/id-cards/grid-download/{gridDownload}', [EventAttendeeController::class, 'showIdCardGridDownload'])->middleware('throttle:60,1,id-card-grid-status:')->name('events.registrations.id-card-grid-download');
        Route::get('events/{event}/registrations/id-cards/grid-download/{gridDownload}/file', [EventAttendeeController::class, 'downloadIdCardGridDownload'])->middleware('throttle:60,1,id-card-grid-file:')->name('events.registrations.id-card-grid-download-file');
        Route::apiResource('events.registrations', EventAttendeeController::class)
            ->only(['index', 'store', 'show', 'destroy']);
        Route::get('events/{event}/registrations/{registration}/id-card', [EventAttendeeController::class, 'downloadIdCard']);
        Route::get('events/{event}/registrations/{registration}/id-card-image', [EventAttendeeController::class, 'getIdCardImage'])->name('events.registrations.id-card-image');
        Route::post('events/{event}/registrations/{registration}/id-card/regenerate', [EventAttendeeController::class, 'regenerateIdCard'])->middleware('throttle:120,1,id-card-regenerate:');

        Route::get('events/{event}/sessions/{session}/attendance', [AttendanceController::class, 'forSession']);
        Route::get('events/{event}/sessions/{session}/roster', [AttendanceController::class, 'roster']);
        Route::get('events/{event}/sessions/{session}/quick-stats', [ReportController::class, 'eventSessionQuickStats']);

        Route::get('events/{event}/reports/attendance-summary', [ReportController::class, 'eventAttendanceSummary']);
        Route::get('events/{event}/reports/attendance-summary/export', [ReportController::class, 'exportEventAttendance'])->middleware('throttle:6,1');
        Route::get('events/{event}/reports/breakouts', [ReportController::class, 'breakoutReport']);
        Route::get('events/{event}/reports/breakouts/{breakout}', [ReportController::class, 'breakoutRoster'])->scopeBindings();
        Route::get('events/{event}/reports/attendee-summary', [ReportController::class, 'attendeeSummary']);
        Route::get('events/{event}/reports/attendee-summary/export', [ReportController::class, 'exportAttendeeSummary'])->middleware('throttle:6,1');
        Route::get('events/{event}/reports/dashboard', [ReportController::class, 'eventDashboard']);
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
