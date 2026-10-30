<?php

namespace App\Services\GlobalSearch;

use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Global search over Breakouts: title, description, room, topics and speaker names. */
class BreakoutSearch
{
    use BuildsSearchItems;

    /**
     * Attendees are not offered Breakouts while the event has them disabled. Topics are stored as JSON text, so they
     * are matched against the JSON-encoded term (non-ASCII text is stored as \uXXXX escapes).
     */
    public function query(User $user, Builder $events, string $term, string $like): Builder
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

    public function mapper(User $user): \Closure
    {
        $picked = $user->isAttendee()
            ? DB::table('breakout_picks')
                ->whereIn('attendee_id', Attendee::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('merged_at')->select('id'))
                ->pluck('breakout_id')->flip()
            : null;

        return fn (Breakout $record) => $this->item(
            'breakout', $record->id, $record->title, $this->subtitle($record), $record->event?->name, $record->event_id,
            $picked === null ? [] : ['picked' => $picked->has($record->id)],
        );
    }

    private function subtitle(Breakout $breakout): string
    {
        $time = substr((string) $breakout->start_time, 0, 5).'-'.substr((string) $breakout->end_time, 0, 5);

        return trim($breakout->date?->toDateString().' '.$time.($breakout->room ? ' · '.$breakout->room : ''));
    }
}
