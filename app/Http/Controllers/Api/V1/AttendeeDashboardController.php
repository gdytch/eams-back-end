<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendeeDashboardResource;
use App\Http\Resources\EventProgramResource;
use App\Http\Resources\EventRegistrationResource;
use App\Http\Resources\SpeakerResource;
use App\Models\Attendee;
use App\Models\Event;
use App\Services\AttendeeDashboardData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendeeDashboardController extends Controller
{
    /**
     * Get the authenticated attendee's dashboard: profile, account, upcoming/past events, and stats.
     */
    public function index(Request $request, AttendeeDashboardData $dashboardData)
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::with(['union', 'mission', 'church'])->where('user_id', $user->id)->first();

        if ($attendee === null) {
            return AttendeeDashboardResource::make($dashboardData->emptyDashboard($user))->response();
        }

        $registrations = $attendee->registrations()
            ->with(['event.sessions', 'attendanceRecords'])
            ->get();

        return AttendeeDashboardResource::make($dashboardData->attendeeDashboard($user, $attendee, $registrations))->response();
    }

    /**
     * List all registrations for the authenticated attendee.
     */
    public function registrations(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->isAttendee() || $request->user()->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $user = $request->user();
        $attendee = Attendee::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();

        $registrations = $attendee->registrations()
            ->with(['event' => fn ($query) => $query->withoutGlobalScopes(), 'attendanceRecords'])
            ->orderBy('created_at', 'desc')
            ->get();

        return EventRegistrationResource::collection($registrations);
    }

    /**
     * Show the authenticated attendee's session attendance for one registered event.
     */
    public function attendance(Request $request, int $event, AttendeeDashboardData $dashboardData): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $event = Event::withoutGlobalScopes()->findOrFail($event);
        $registration = $attendee->registrations()
            ->where('event_id', $event->id)
            ->with(['attendanceRecords' => fn ($query) => $query->whereNotNull('check_in_at')])
            ->firstOrFail();
        $sessions = $event->sessions()
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get();

        return response()->json($dashboardData->eventAttendance($event, $registration, $sessions));
    }

    /**
     * Show the program for one event registered to the authenticated attendee.
     */
    public function program(Request $request, int $event): EventProgramResource
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $event = Event::withoutGlobalScopes()->findOrFail($event);
        abort_unless(
            $attendee->registrations()->where('event_id', $event->id)->exists(),
            404,
            'Registration not found.'
        );

        $program = $event->program()->with('days.sections.items.speaker')->firstOrFail();

        return EventProgramResource::make($program);
    }

    public function speakers(Request $request, int $eventId): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $event = Event::withoutGlobalScopes()->findOrFail($eventId);
        abort_unless($attendee->registrations()->where('event_id', $event->id)->exists(), 404, 'Registration not found.');

        return SpeakerResource::collection($event->speakers()->with('programItems')->get());
    }
}
