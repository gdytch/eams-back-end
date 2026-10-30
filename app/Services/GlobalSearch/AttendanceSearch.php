<?php

namespace App\Services\GlobalSearch;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Global search over counted check-ins: Attendee name, Session name and Breakout title. */
class AttendanceSearch
{
    use BuildsSearchItems;

    public function query(User $user, Builder $events, string $like): Builder
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

    public function toItem(AttendanceRecord $record): array
    {
        return $this->item('attendance', $record->id, $record->attendee?->full_name ?: 'Attendance', $record->breakout_id ? $record->breakout?->title : $record->eventSession?->name, $record->attendee?->event?->name, $record->attendee?->event_id, ['session_id' => $record->breakout_id ? null : $record->event_session_id, 'breakout_id' => $record->breakout_id, 'is_walk_in' => (bool) $record->is_walk_in, 'registration_id' => $record->attendee_id]);
    }
}
