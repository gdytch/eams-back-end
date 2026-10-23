<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendeeDashboardResource;
use App\Http\Resources\AttendeeProgramBreakoutResource;
use App\Http\Resources\EventAttendeeResource;
use App\Http\Resources\EventProgramResource;
use App\Http\Resources\SpeakerResource;
use App\Models\Attendee;
use App\Models\Event;
use App\Services\AttendeeDashboardData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AttendeeDashboardController extends Controller
{
    /**
     * Get the authenticated attendee's dashboard: profile, account, upcoming/past events, and stats.
     */
    public function index(Request $request, AttendeeDashboardData $dashboardData)
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::with(['union', 'mission', 'church'])->where('user_id', $user->id)->latest('id')->first();

        if ($attendee === null) {
            return AttendeeDashboardResource::make($dashboardData->emptyDashboard($user))->response();
        }

        $registrations = $user->attendees()
            ->withoutGlobalScopes()
            ->whereHas('event')
            ->with(['event.sessions', 'event.program', 'attendanceRecords'])
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
        abort_unless($user->attendees()->withoutGlobalScopes()->exists(), 404);

        $registrations = $user->attendees()
            ->withoutGlobalScopes()
            ->with(['event' => fn ($query) => $query->withoutGlobalScopes(), 'attendanceRecords'])
            ->orderBy('created_at', 'desc')
            ->get();

        return EventAttendeeResource::collection($registrations);
    }

    /**
     * Show the authenticated attendee's session attendance for one registered event.
     */
    public function attendance(Request $request, int $event, AttendeeDashboardData $dashboardData): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $event = Event::withoutGlobalScopes()->findOrFail($event);
        $registration = $user->attendees()
            ->withoutGlobalScopes()
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
    public function program(Request $request, int $event): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $event = Event::withoutGlobalScopes()->findOrFail($event);
        $attendee = $user->attendees()->withoutGlobalScopes()->where('event_id', $event->id)->first();
        abort_unless($attendee !== null, 404, 'Registration not found.');

        $program = $event->program()->with('days.sections.items.speaker')->firstOrFail();

        return response()->json(['data' => EventProgramResource::make($program)->resolve() + [
            'breakouts' => $this->programBreakouts($event, $attendee, (bool) $program->is_public),
        ]]);
    }

    /**
     * Flat breakout list with the attendee's own picks flagged. Own picks are always
     * listed; other breakouts only while the program is public. No pick mode, no breakouts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function programBreakouts(Event $event, Attendee $attendee, bool $programIsPublic): array
    {
        if ($event->pick_mode === null) {
            return [];
        }

        $pickedIds = DB::table('breakout_picks')->where('attendee_id', $attendee->id)->pluck('breakout_id')->map(fn ($id) => (int) $id)->all();

        $breakouts = $event->breakouts()->withoutGlobalScopes()
            ->when(! $programIsPublic, fn ($query) => $query->whereIn('id', $pickedIds))
            ->with('speakers')
            ->orderBy('date')->orderBy('start_time')
            ->get()
            ->each(fn ($breakout) => $breakout->setAttribute('picked', in_array($breakout->id, $pickedIds, true)));

        return AttendeeProgramBreakoutResource::collection($breakouts)->resolve();
    }

    public function speakers(Request $request, int $eventId): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $event = Event::withoutGlobalScopes()->findOrFail($eventId);
        abort_unless($user->attendees()->withoutGlobalScopes()->where('event_id', $event->id)->exists(), 404, 'Registration not found.');

        return SpeakerResource::collection($event->speakers()->with('programItems')->get());
    }
}
