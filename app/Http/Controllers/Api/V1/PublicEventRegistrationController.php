<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveWizardTerritoryRequest;
use App\Http\Resources\EventAttendeeResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Services\AttendeeLinkingService;
use App\Services\AttendeeRegistrationService;
use App\Services\RegistrationCompleteness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public registration wizard: each step is one server save under the event invite token.
 * The first Basic info save creates the Draft Attendee; later steps write to the same row.
 */
class PublicEventRegistrationController extends Controller
{
    public function __construct(
        private AttendeeRegistrationService $registration,
        private AttendeeLinkingService $linking,
        private RegistrationCompleteness $completeness,
    ) {}

    public function show(Request $request, string $eventInviteToken): JsonResponse
    {
        $event = $this->resolvePublishedEvent($eventInviteToken);

        return DB::transaction(function () use ($request, $event) {
            if ($this->mustVerifyEmail($request->user(), $event)) {
                return $this->verifyEmailResponse();
            }

            $attendee = $this->registration->userAttendeeIn($request->user(), $event);

            return $attendee === null
                ? response()->json(['status' => 'ready', 'data' => null, 'steps' => $this->completeness->steps(new Attendee)])
                : $this->respond($attendee);
        });
    }

    public function saveBasicInfo(Request $request, string $eventInviteToken): JsonResponse
    {
        $event = $this->resolveWritableEvent($eventInviteToken);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile_no' => ['required', 'string', 'max:20'],
        ]);

        return $this->save($request->user(), $event, $data, ['first_name', 'middle_name', 'last_name'], creates: true);
    }

    public function saveTerritory(SaveWizardTerritoryRequest $request, string $eventInviteToken): JsonResponse
    {
        $event = $this->resolveWritableEvent($eventInviteToken);

        return $this->save($request->user(), $event, $request->validated(), ['organization_level', 'union_id', 'mission_id', 'church_id']);
    }

    public function finish(Request $request, string $eventInviteToken): JsonResponse
    {
        $event = $this->resolveWritableEvent($eventInviteToken);

        return DB::transaction(function () use ($request, $event) {
            if ($this->mustVerifyEmail($request->user(), $event)) {
                return $this->verifyEmailResponse();
            }

            $attendee = $this->registration->userAttendeeIn($request->user(), $event) ?? abort(404);
            $wasRegistered = $attendee->first_registered_at !== null;

            $attendee->update(['registration_confirmed_at' => now()]);

            if (! $attendee->registration_completed_at) {
                $missing = array_filter(array_diff_key($this->completeness->steps($attendee), ['review' => 1]));
                // Roll back the saved row: the hook already cleared the confirmation, so nothing persists.
                throw ValidationException::withMessages(['steps' => array_keys($missing)])->errorBag('registration');
            }

            AuditLog::record('event_registration.finished', $attendee, ['attendee_id' => $attendee->id]);

            if (! $wasRegistered) {
                GenerateAttendeeIdCardJob::dispatch($attendee)->afterCommit();
            }

            return $this->respond($attendee);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $cardFields  Fields shown on the ID card; a change after registration regenerates it.
     */
    private function save(User $user, Event $event, array $data, array $cardFields, bool $creates = false): JsonResponse
    {
        return DB::transaction(function () use ($user, $event, $data, $cardFields, $creates) {
            if ($this->mustVerifyEmail($user, $event)) {
                return $this->verifyEmailResponse();
            }

            $attendee = $this->registration->userAttendeeIn($user, $event);

            if ($attendee === null) {
                abort_unless($creates, 422, 'Save Basic info first.');

                [$attendee] = $this->registration->registerUser($user, $event);
                AuditLog::record('event_registration.self_registered', $attendee, ['attendee_id' => $attendee->id]);

                if ($user->organization_id === null) {
                    $user->update(['organization_id' => $event->organization_id]);
                }
            }

            $attendee->fill($data)->save();

            if ($attendee->first_registered_at !== null && $attendee->wasChanged($cardFields)) {
                GenerateAttendeeIdCardJob::dispatch($attendee)->afterCommit();
            }

            return $this->respond($attendee);
        });
    }

    private function respond(Attendee $attendee): JsonResponse
    {
        return EventAttendeeResource::make($attendee)
            ->additional(['status' => 'ready', 'steps' => $this->completeness->steps($attendee)])
            ->response()
            ->setStatusCode(200); // a resource answers 201 for a freshly created model
    }

    /**
     * An Attendee without a login holds the user's email and it is not verified yet: linking waits for verification.
     */
    private function mustVerifyEmail(User $user, Event $event): bool
    {
        // Serialises one user's saves (two devices, double click) before the first read, so create and finish run once.
        User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->first();

        $this->linking->linkVerifiedEmail($user);

        return $this->registration->userAttendeeIn($user, $event) === null
            && $this->linking->hasUnlinkedAttendeeIn($user, $event->id);
    }

    private function verifyEmailResponse(): JsonResponse
    {
        return response()->json(['status' => 'verify_email', 'message' => 'Verify your email to continue.'], 409);
    }

    private function resolveWritableEvent(string $inviteToken): Event
    {
        $event = $this->resolvePublishedEvent($inviteToken);
        $this->authorize('register', $event);

        return $event;
    }

    private function resolvePublishedEvent(string $inviteToken): Event
    {
        $event = Event::withoutGlobalScopes()->where('invite_token', $inviteToken)->firstOrFail();

        abort_unless($event->status === EventStatus::Published, 404);

        return $event;
    }
}
