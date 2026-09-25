<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GlobalSearchRequest;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Union;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request): JsonResponse
    {
        $input = $request->validated();

        return response()->json($this->search(
            $request->user(),
            $input['q'],
            isset($input['event_id']) ? (int) $input['event_id'] : null,
            $request->boolean('all_events'),
            $input['category'] ?? null,
            (int) ($input['page'] ?? 1),
            (int) ($input['per_page'] ?? 10),
        ));
    }

    private function search(
        User $user,
        string $term,
        ?int $eventId,
        bool $allEvents,
        ?string $category,
        int $page,
        int $perPage,
    ): array {
        $events = $this->accessibleEvents($user);
        if ($eventId !== null && ! $allEvents) {
            abort_unless((clone $events)->whereKey($eventId)->exists(), 403);
            $events->whereKey($eventId);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $categories = $this->categories($user);
        if ($category !== null) {
            abort_unless(in_array($category, $categories, true), 403);
            $categories = [$category];
        }

        $groups = [];
        foreach ($categories as $key) {
            $groups[] = $key === 'program'
                ? $this->programGroup($events, $like, $category === null ? 1 : $page, $category === null ? 5 : $perPage)
                : $this->groupFor($key, $user, $events, $like, $category === null ? 1 : $page, $category === null ? 5 : $perPage, $eventId !== null && ! $allEvents);
        }

        return ['groups' => $groups];
    }

    private function categories(User $user): array
    {
        $categories = [];
        if ($user->isSuperAdmin()) {
            $categories[] = 'organizations';
        }
        if ($user->isSuperAdmin() || $user->isOrgAdmin()) {
            array_push($categories, 'users', 'unions', 'missions', 'churches');
        }
        if (! $user->isAttendee()) {
            $categories[] = 'attendees';
        }
        array_push($categories, 'events', 'sessions', 'registrations', 'attendance', 'program');
        if (! $user->isChecker()) {
            $categories[] = 'speakers';
        }

        return $categories;
    }

    private function accessibleEvents(User $user): Builder
    {
        $events = Event::withoutGlobalScopes();

        if ($user->isAttendee()) {
            return $events->whereHas('registrations.attendee', fn (Builder $query) => $query
                ->withoutGlobalScopes()->where('user_id', $user->id));
        }
        if (! $user->isSuperAdmin()) {
            $events->where('events.organization_id', $user->organization_id);
        }
        if ($user->isChecker() && $user->accessibleEvents()->exists()) {
            $events->whereIn('events.id', $user->accessibleEvents()->select('events.id'));
        }

        return $events;
    }

    private function groupFor(string $key, User $user, Builder $events, string $like, int $page, int $perPage, bool $scoped): array
    {
        [$query, $map] = match ($key) {
            'organizations' => [
                $this->matches(Organization::query(), ['name', 'code'], $like),
                fn (Organization $record) => $this->item('organization', $record->id, $record->name, $record->code),
            ],
            'users' => [
                $this->matches(User::query()->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where('organization_id', $user->organization_id)), ['name', 'email'], $like),
                fn (User $record) => $this->item('user', $record->id, $record->name, $record->email),
            ],
            'attendees' => [
                $this->matches(Attendee::query()->when($scoped || $user->isChecker(), fn (Builder $query) => $query->whereHas('registrations', fn (Builder $registrations) => $registrations->whereIn('event_id', (clone $events)->select('events.id')))), ['first_name', 'middle_name', 'last_name', 'normalized_name', 'email_address', 'mobile_no'], $like),
                fn (Attendee $record) => $this->item('attendee', $record->id, $record->full_name, $record->email_address ?: $record->mobile_no),
            ],
            'unions' => [
                $this->matches(Union::query(), ['name', 'code'], $like),
                fn (Union $record) => $this->item('union', $record->id, $record->name, $record->code),
            ],
            'missions' => [
                $this->matches(Mission::query(), ['name', 'code'], $like),
                fn (Mission $record) => $this->item('mission', $record->id, $record->name, $record->code),
            ],
            'churches' => [
                $this->matches(Church::query(), ['name', 'address'], $like),
                fn (Church $record) => $this->item('church', $record->id, $record->name, $record->address),
            ],
            'events' => [
                $this->matches(Event::withoutGlobalScopes()->whereIn('events.id', (clone $events)->select('events.id')), ['name', 'description', 'venue'], $like),
                fn (Event $record) => $this->item('event', $record->id, $record->name, $record->venue, $record->name, $record->id),
            ],
            'sessions' => [
                $this->matches(EventSession::query()->whereIn('event_id', (clone $events)->select('events.id'))->with(['event' => fn ($query) => $query->withoutGlobalScopes()]), ['name', 'description'], $like),
                fn (EventSession $record) => $this->item('session', $record->id, $record->name, $record->session_date?->toDateString(), $record->event?->name, $record->event_id),
            ],
            'registrations' => [
                $this->registrationQuery($user, $events, $like),
                fn (EventRegistration $record) => $this->item('registration', $record->id, $record->attendee?->full_name ?: 'Registration', $record->attendee?->email_address, $record->event?->name, $record->event_id),
            ],
            'attendance' => [
                $this->attendanceQuery($user, $events, $like),
                fn (AttendanceRecord $record) => $this->item('attendance', $record->id, $record->eventRegistration?->attendee?->full_name ?: 'Attendance', $record->eventSession?->name, $record->eventRegistration?->event?->name, $record->eventRegistration?->event_id, ['session_id' => $record->event_session_id, 'registration_id' => $record->event_registration_id]),
            ],
            'speakers' => [
                $this->matches(Speaker::query()->whereIn('event_id', (clone $events)->select('events.id'))->with(['event' => fn ($query) => $query->withoutGlobalScopes()]), ['name', 'designation', 'organization', 'bio'], $like),
                fn (Speaker $record) => $this->item('speaker', $record->id, $record->name, $record->designation, $record->event?->name, $record->event_id),
            ],
        };

        $total = (clone $query)->count();
        $items = $query->orderBy('id')->forPage($page, $perPage)->get()->map($map)->all();

        return $this->group($key, $total, $items, $page, $perPage);
    }

    private function registrationQuery(User $user, Builder $events, string $like): Builder
    {
        $query = EventRegistration::query()
            ->whereIn('event_id', (clone $events)->select('events.id'))
            ->with([
                'attendee' => fn ($attendee) => $attendee->withoutGlobalScopes(),
                'event' => fn ($event) => $event->withoutGlobalScopes(),
            ]);
        if ($user->isAttendee()) {
            $query->whereHas('attendee', fn (Builder $attendee) => $attendee->withoutGlobalScopes()->where('user_id', $user->id));
        }

        return $query->whereHas('attendee', fn (Builder $attendee) => $this->matches($attendee->withoutGlobalScopes(), ['first_name', 'middle_name', 'last_name', 'normalized_name', 'email_address', 'mobile_no'], $like));
    }

    private function attendanceQuery(User $user, Builder $events, string $like): Builder
    {
        $query = AttendanceRecord::query()
            ->whereNotNull('check_in_at')
            ->whereHas('eventRegistration', function (Builder $registration) use ($user, $events) {
                $registration->whereIn('event_id', (clone $events)->select('events.id'));
                if ($user->isAttendee()) {
                    $registration->whereHas('attendee', fn (Builder $attendee) => $attendee->withoutGlobalScopes()->where('user_id', $user->id));
                }
            })
            ->with([
                'eventRegistration.attendee' => fn ($attendee) => $attendee->withoutGlobalScopes(),
                'eventRegistration.event' => fn ($event) => $event->withoutGlobalScopes(),
                'eventSession',
            ]);

        return $query->where(function (Builder $match) use ($like) {
            $match->whereHas('eventRegistration.attendee', fn (Builder $attendee) => $this->matches($attendee->withoutGlobalScopes(), ['first_name', 'middle_name', 'last_name', 'normalized_name', 'email_address', 'mobile_no'], $like))
                ->orWhereHas('eventSession', fn (Builder $session) => $this->matches($session, ['name', 'description'], $like));
        });
    }

    private function programGroup(Builder $events, string $like, int $page, int $perPage): array
    {
        $eventIds = fn () => (clone $events)->select('events.id');
        $queries = [
            ['program', $this->matches(EventProgram::query()->whereIn('event_id', $eventIds())->with(['event' => fn ($query) => $query->withoutGlobalScopes()]), ['title', 'description'], $like),
                fn (EventProgram $record) => $this->item('program', $record->id, $record->title ?: 'Event program', $record->description, $record->event?->name, $record->event_id)],
            ['program_day', $this->matches(EventProgramDay::query()->whereHas('program', fn (Builder $query) => $query->whereIn('event_id', $eventIds()))->with(['program.event' => fn ($query) => $query->withoutGlobalScopes()]), ['title'], $like),
                fn (EventProgramDay $record) => $this->item('program_day', $record->id, $record->title ?: $record->date?->toDateString(), $record->date?->toDateString(), $record->program?->event?->name, $record->program?->event_id)],
            ['program_section', $this->matches(EventProgramSection::query()->whereHas('day.program', fn (Builder $query) => $query->whereIn('event_id', $eventIds()))->with(['day.program.event' => fn ($query) => $query->withoutGlobalScopes()]), ['title', 'description'], $like),
                fn (EventProgramSection $record) => $this->item('program_section', $record->id, $record->title, $record->description, $record->day?->program?->event?->name, $record->day?->program?->event_id)],
            ['program_item', $this->matches(EventProgramItem::query()->whereHas('program', fn (Builder $query) => $query->whereIn('event_id', $eventIds()))->with(['program.event' => fn ($query) => $query->withoutGlobalScopes()]), ['part_title', 'part_subtitle', 'part_description', 'participant_name', 'participant_description'], $like),
                fn (EventProgramItem $record) => $this->item('program_item', $record->id, $record->part_title ?: $record->participant_name ?: 'Program part', $record->part_subtitle, $record->program?->event?->name, $record->event_program_id ? $record->program?->event_id : null)],
        ];

        $total = 0;
        $items = collect();
        foreach ($queries as [, $query, $map]) {
            $total += (clone $query)->count();
            $items = $items->concat($query->orderBy('id')->limit($page * $perPage)->get()->map($map));
        }

        return $this->group('program', $total, $items->slice(($page - 1) * $perPage, $perPage)->values()->all(), $page, $perPage);
    }

    private function matches(Builder $query, array $columns, string $like): Builder
    {
        return $query->where(function (Builder $match) use ($columns, $like) {
            foreach ($columns as $column) {
                $match->orWhere($column, 'like', $like);
            }
        });
    }

    private function item(string $type, int $id, ?string $title, ?string $subtitle = null, ?string $context = null, ?int $eventId = null, array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'id' => $id,
            'title' => $title ?: 'Untitled',
            'subtitle' => $subtitle,
            'context' => $context,
            'event_id' => $eventId,
        ], $extra);
    }

    private function group(string $key, int $total, array $items, int $page, int $perPage): array
    {
        return [
            'key' => $key,
            'total' => $total,
            'items' => $items,
            'page' => $page,
            'has_more' => $total > $page * $perPage,
        ];
    }
}
