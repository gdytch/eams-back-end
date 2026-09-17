<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventRegistrationResource;
use App\Http\Resources\PublicEventResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Mail\EventRegistrationWelcomeMail;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PublicEventController extends Controller
{
    /**
     * Display event landing page data by invite token (public, no auth required).
     */
    public function show(string $eventInviteToken): JsonResponse|PublicEventResource
    {
        $event = $this->resolvePublishedEvent($eventInviteToken);

        return PublicEventResource::make($event);
    }

    /**
     * Register authenticated user to event (or create Attendee if needed).
     */
    public function register(Request $request, string $eventInviteToken): JsonResponse
    {
        $event = $this->resolvePublishedEvent($eventInviteToken);
        $user = $request->user();

        return DB::transaction(function () use ($event, $user) {
            // Try to get existing attendee without global scopes (in case it exists but is from another org)
            $attendee = Attendee::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->first();

            if ($attendee === null) {
                try {
                    $firstName = $user->first_name ?? Str::before($user->name, ' ');
                    $lastName = $user->last_name ?? Str::after($user->name, ' ');

                    $attendee = Attendee::create([
                        'organization_id' => $event->organization_id,
                        'first_name' => $firstName,
                        'middle_name' => $user->middle_name,
                        'last_name' => $lastName,
                        'email_address' => $user->email,
                        'user_id' => $user->id,
                        'created_by' => $user->id,
                    ]);

                    if ($user->organization_id === null) {
                        $user->update(['organization_id' => $event->organization_id]);
                    }
                } catch (UniqueConstraintViolationException) {
                    // User already has an attendee record
                    $attendee = Attendee::withoutGlobalScopes()
                        ->where('user_id', $user->id)
                        ->firstOrFail();
                }
            }

            $registration = EventRegistration::firstOrCreate(
                ['event_id' => $event->id, 'attendee_id' => $attendee->id],
                ['registered_by' => $user->id]
            );

            if ($registration->wasRecentlyCreated) {
                AuditLog::record('event_registration.self_registered', $registration, ['attendee_id' => $attendee->id]);
                GenerateAttendeeIdCardJob::dispatch($registration);

                // Send welcome email if attendee has an email
                // Temporarily disabled sending welcome email to because of email sending rate limits. Can be re-enabled later if needed.
                // $email = $attendee->email_address ?? $attendee->user?->email;
                // if ($email) {
                //     Mail::to($email)->send(new EventRegistrationWelcomeMail($registration));
                // }

                return EventRegistrationResource::make($registration->load('attendee'))
                    ->response()
                    ->setStatusCode(201);
            }

            return EventRegistrationResource::make($registration->load('attendee'))
                ->response()
                ->setStatusCode(200);
        });
    }

    /**
     * Resolve event by invite token, ensuring it's published (404 otherwise).
     */
    private function resolvePublishedEvent(string $inviteToken): Event
    {
        $event = Event::withoutGlobalScopes()
            ->where('invite_token', $inviteToken)
            ->with(['organization', 'sessions', 'program.items'])
            ->firstOrFail();

        abort_unless($event->status === EventStatus::Published, 404);

        return $event;
    }
}
