<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventRegistrationResource;
use App\Http\Resources\PublicEventResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\AuditLog;
use App\Models\Event;
use App\Services\AttendeeRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicEventController extends Controller
{
    /**
     * Display event landing page data by invite token (public, no auth required).
     */
    public function show(string $eventInviteToken): JsonResponse|PublicEventResource
    {
        $event = $this->resolveEventByInviteToken($eventInviteToken);

        // Archived events show the closed state whatever their status; drafts stay hidden.
        abort_unless(
            $event->status === EventStatus::Published || ($event->isArchived() && $event->status !== EventStatus::Draft),
            404
        );

        return PublicEventResource::make($event);
    }

    /**
     * Register authenticated user to the event: one per-event Attendee, prefilled from their latest Attendee.
     */
    public function register(Request $request, string $eventInviteToken, AttendeeRegistrationService $registrationService): JsonResponse
    {
        $event = $this->resolveEventOpenForRegistration($eventInviteToken);
        $user = $request->user();

        return DB::transaction(function () use ($event, $user, $registrationService) {
            [$attendee, $created] = $registrationService->registerUser($user, $event);

            if ($created) {
                if ($user->organization_id === null) {
                    $user->update(['organization_id' => $event->organization_id]);
                }

                AuditLog::record('event_registration.self_registered', $attendee, ['attendee_id' => $attendee->id]);
                GenerateAttendeeIdCardJob::dispatch($attendee);

                // Send welcome email if attendee has an email
                // Temporarily disabled sending welcome email to because of email sending rate limits. Can be re-enabled later if needed.
                // $email = $attendee->email_address ?? $attendee->user?->email;
                // if ($email) {
                //     Mail::to($email)->send(new EventRegistrationWelcomeMail($attendee));
                // }
            }

            return EventRegistrationResource::make($attendee)
                ->response()
                ->setStatusCode($created ? 201 : 200);
        });
    }

    /**
     * Resolve a published event that still accepts registrations (403 once archived).
     */
    private function resolveEventOpenForRegistration(string $inviteToken): Event
    {
        $event = $this->resolvePublishedEvent($inviteToken);
        abort_if($event->isArchived(), 403, 'This event is archived.');

        return $event;
    }

    /**
     * Resolve event by invite token, ensuring it's published (404 otherwise).
     */
    private function resolvePublishedEvent(string $inviteToken): Event
    {
        $event = $this->resolveEventByInviteToken($inviteToken);

        abort_unless($event->status === EventStatus::Published, 404);

        return $event;
    }

    /**
     * Resolve event by invite token in any status (404 when the token is unknown).
     */
    private function resolveEventByInviteToken(string $inviteToken): Event
    {
        return Event::withoutGlobalScopes()
            ->where('invite_token', $inviteToken)
            ->with(['organization', 'sessions', 'program.items', 'speakers.programItems'])
            ->firstOrFail();
    }
}
