<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GlobalSearchRequest;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Certificate;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\EventSession;
use App\Models\Form;
use App\Models\IssuedCertificate;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Union;
use App\Models\User;
use App\Services\AttendeeCertificates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

        $groupPage = $category === null ? 1 : $page;
        $groupSize = $category === null ? 5 : $perPage;
        $scoped = $eventId !== null && ! $allEvents;

        $groups = [];
        foreach ($categories as $key) {
            $groups[] = match (true) {
                $key === 'program' => $this->programGroup($events, $like, $groupPage, $groupSize),
                $key === 'certificates' && $user->isAttendee() => $this->issuedCertificatesGroup($user, $events, $term, $groupPage, $groupSize),
                default => $this->groupFor($key, $user, $events, $term, $like, $groupPage, $groupSize, $scoped),
            };
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
        array_push($categories, 'events', 'sessions', 'breakouts');
        if ($user->isSuperAdmin() || $user->isOrgAdmin()) {
            $categories[] = 'forms';
        }
        if (! $user->isChecker()) {
            $categories[] = 'certificates';
        }
        array_push($categories, 'registrations', 'attendance', 'program');
        if (! $user->isChecker()) {
            $categories[] = 'speakers';
        }

        return $categories;
    }

    private function accessibleEvents(User $user): Builder
    {
        $events = Event::withoutGlobalScopes();

        if ($user->isAttendee()) {
            return $events->whereHas('attendees', fn (Builder $query) => $query
                ->withoutGlobalScopes()->where('user_id', $user->id));
        }
        if (! $user->isSuperAdmin()) {
            $events->where('events.organization_id', $user->organization_id);
        }
        if ($user->isChecker()) {
            $events->whereNull('events.archived_at')
                ->when($user->accessibleEvents()->exists(), fn (Builder $query) => $query->whereIn('events.id', $user->accessibleEvents()->select('events.id')));
        }

        return $events;
    }

    private function groupFor(string $key, User $user, Builder $events, string $term, string $like, int $page, int $perPage, bool $scoped): array
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
                $this->matches(Attendee::query()->when($scoped || $user->isChecker(), fn (Builder $query) => $query->whereIn('event_id', (clone $events)->select('events.id'))), ['first_name', 'middle_name', 'last_name', 'normalized_name', 'email_address', 'mobile_no'], $like),
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
            'breakouts' => [
                $this->breakoutQuery($user, $events, $term, $like),
                $this->breakoutMapper($user),
            ],
            'forms' => [
                $this->formQuery($events, $like),
                fn (Form $record) => $this->item('form', $record->id, $record->title, $this->formSubtitle($record), $record->event?->name, $record->event_id),
            ],
            'certificates' => [
                $this->matches(Certificate::withoutGlobalScopes()->whereIn('event_id', (clone $events)->select('events.id'))->with(['event' => fn ($event) => $event->withoutGlobalScopes()]), ['title'], $like),
                fn (Certificate $record) => $this->item('certificate', $record->id, $record->title, $this->certificateSubtitle($record), $record->event?->name, $record->event_id),
            ],
            'registrations' => [
                $this->registrationQuery($user, $events, $like),
                fn (Attendee $record) => $this->item('registration', $record->id, $record->full_name ?: 'Registration', $record->email_address, $record->event?->name, $record->event_id),
            ],
            'attendance' => [
                $this->attendanceQuery($user, $events, $like),
                fn (AttendanceRecord $record) => $this->item('attendance', $record->id, $record->attendee?->full_name ?: 'Attendance', $record->breakout_id ? $record->breakout?->title : $record->eventSession?->name, $record->attendee?->event?->name, $record->attendee?->event_id, ['session_id' => $record->breakout_id ? null : $record->event_session_id, 'breakout_id' => $record->breakout_id, 'is_walk_in' => (bool) $record->is_walk_in, 'registration_id' => $record->attendee_id]),
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

    /**
     * An Attendee's own Issued certificates, exactly those My Certificates lists (one batched service call across the
     * accessible events), filtered by Certificate title in memory.
     */
    private function issuedCertificatesGroup(User $user, Builder $events, string $term, int $page, int $perPage): array
    {
        $eventsById = (clone $events)->get(['events.id', 'events.name'])->keyBy('id');
        $needle = mb_strtolower($term);
        $rows = app(AttendeeCertificates::class)->availableForEvents($user, $eventsById->keys()->all())
            ->filter(fn (IssuedCertificate $issued) => str_contains(mb_strtolower((string) $issued->certificate->title), $needle))
            ->values();

        $items = $rows->forPage($page, $perPage)->map(fn (IssuedCertificate $issued) => $this->item(
            'issued_certificate', $issued->id, $issued->certificate->title, null, $eventsById[$issued->certificate->event_id]->name, $issued->certificate->event_id,
            ['certificate_id' => $issued->certificate_id],
        ))->values()->all();

        return $this->group('certificates', $rows->count(), $items, $page, $perPage);
    }

    /**
     * Attendees are not offered Breakouts while the event has them disabled. Topics are stored as JSON text, so they
     * are matched against the JSON-encoded term (non-ASCII text is stored as \uXXXX escapes).
     */
    private function breakoutQuery(User $user, Builder $events, string $term, string $like): Builder
    {
        $query = Breakout::withoutGlobalScopes()
            ->whereIn('event_id', (clone $events)->select('events.id'))
            ->with(['event' => fn ($event) => $event->withoutGlobalScopes()])
            ->when($user->isAttendee(), fn (Builder $query) => $query->whereHas('event', fn (Builder $event) => $event->withoutGlobalScopes()->whereNotNull('pick_mode')));

        $topicLike = '%'.addcslashes(substr(json_encode($term), 1, -1), '%_\\').'%';

        return $query->where(function (Builder $match) use ($like, $topicLike) {
            $this->matches($match, ['title', 'description', 'room'], $like)
                ->orWhereRaw($this->likeSql($match, 'topics'), [$topicLike, '\\'])
                ->orWhereHas('speakers', fn (Builder $speaker) => $this->matches($speaker->withoutGlobalScopes(), ['name'], $like));
        });
    }

    private function breakoutMapper(User $user): \Closure
    {
        $picked = $user->isAttendee()
            ? DB::table('breakout_picks')
                ->whereIn('attendee_id', Attendee::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('merged_at')->select('id'))
                ->pluck('breakout_id')->flip()
            : null;

        return fn (Breakout $record) => $this->item(
            'breakout', $record->id, $record->title, $this->breakoutSubtitle($record), $record->event?->name, $record->event_id,
            $picked === null ? [] : ['picked' => $picked->has($record->id)],
        );
    }

    private function breakoutSubtitle(Breakout $breakout): string
    {
        $time = substr((string) $breakout->start_time, 0, 5).'-'.substr((string) $breakout->end_time, 0, 5);

        return trim($breakout->date?->toDateString().' '.$time.($breakout->room ? ' · '.$breakout->room : ''));
    }

    /**
     * Flags (`matched_itself`) in SQL whether the Form's own title or description matched, and eager-loads only the
     * active fields whose label matched, so the subtitle needs no further query.
     */
    private function formQuery(Builder $events, string $like): Builder
    {
        $query = Form::withoutGlobalScopes()
            ->whereIn('event_id', (clone $events)->select('events.id'))
            ->with([
                'event' => fn ($event) => $event->withoutGlobalScopes(),
                'fields' => fn ($field) => $this->matches($field->getQuery()->active(), ['label'], $like)->orderBy('id'),
            ]);
        $itself = $this->likeSql($query, 'forms.title').' or '.$this->likeSql($query, 'forms.description');
        $query->selectRaw("forms.*, case when {$itself} then 1 else 0 end as matched_itself", [$like, '\\', $like, '\\']);

        return $query->where(function (Builder $match) use ($like) {
            $this->matches($match, ['title', 'description'], $like)
                ->orWhereHas('fields', fn (Builder $field) => $this->matches($field->active(), ['label'], $like));
        });
    }

    private function formSubtitle(Form $form): string
    {
        $label = $form->fields->first()?->label;
        if (! $form->matched_itself && $label !== null) {
            return 'Field: '.$label;
        }

        return ($form->is_required ? 'Required' : 'Optional').' · '.ucfirst($form->status->value);
    }

    private function certificateSubtitle(Certificate $certificate): string
    {
        if (! $certificate->isPublished()) {
            return 'Draft';
        }

        return $certificate->release_at ? 'Published · releases '.$certificate->release_at->format('Y-m-d H:i') : 'Published';
    }

    private function registrationQuery(User $user, Builder $events, string $like): Builder
    {
        $query = Attendee::withoutGlobalScopes()
            ->whereIn('event_id', (clone $events)->select('events.id'))
            ->with(['event' => fn ($event) => $event->withoutGlobalScopes()]);
        if ($user->isAttendee()) {
            $query->where('user_id', $user->id);
        }

        return $this->matches($query, ['first_name', 'middle_name', 'last_name', 'normalized_name', 'email_address', 'mobile_no'], $like);
    }

    private function attendanceQuery(User $user, Builder $events, string $like): Builder
    {
        $query = AttendanceRecord::query()
            ->counted()
            ->whereNotNull('check_in_at')
            ->whereHas('attendee', function (Builder $attendee) use ($user, $events) {
                $attendee->withoutGlobalScopes()->whereIn('event_id', (clone $events)->select('events.id'));
                if ($user->isAttendee()) {
                    $attendee->where('user_id', $user->id);
                }
            })
            ->with([
                'attendee' => fn ($attendee) => $attendee->withoutGlobalScopes(),
                'attendee.event' => fn ($event) => $event->withoutGlobalScopes(),
                'eventSession',
                'breakout' => fn ($breakout) => $breakout->withoutGlobalScopes(),
            ]);

        return $query->where(function (Builder $match) use ($like) {
            $match->whereHas('attendee', fn (Builder $attendee) => $this->matches($attendee->withoutGlobalScopes(), ['first_name', 'middle_name', 'last_name', 'normalized_name', 'email_address', 'mobile_no'], $like))
                ->orWhereHas('eventSession', fn (Builder $session) => $this->matches($session, ['name', 'description'], $like))
                ->orWhereHas('breakout', fn (Builder $breakout) => $this->matches($breakout->withoutGlobalScopes(), ['title'], $like));
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
                $match->orWhereRaw($this->likeSql($match, $column), [$like, '\\']);
            }
        });
    }

    /** A LIKE predicate with an explicit backslash ESCAPE, so escaped wildcards behave the same on every driver. */
    private function likeSql(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($column).' like ? escape ?';
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
