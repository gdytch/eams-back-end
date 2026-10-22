<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Breakout;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saves an Attendee's breakout picks. Picks hold seats from the first save, drafts included.
 */
class BreakoutPickService
{
    /**
     * Replace the Attendee's picks in one transaction that locks the chosen rows in id order.
     * Returns a note when full breakouts were opened because the Attendee has no other choice.
     *
     * @param  array<int, int>  $ids
     * @param  bool  $ignoreCapacity  Admins may exceed capacity.
     */
    public function save(Attendee $attendee, array $ids, bool $ignoreCapacity = false): ?string
    {
        $event = $attendee->event ?? abort(422, 'Join an event first.');
        [$min, $max] = $event->pickRange() ?? abort(422, 'Breakout picks are not enabled for this event.');
        $ids = collect($ids)->unique()->sort()->values();

        return DB::transaction(function () use ($attendee, $event, $ids, $min, $max, $ignoreCapacity) {
            $chosen = Breakout::query()->where('event_id', $event->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            // A locking read sees the latest committed picks; the transaction snapshot may predate the lock.
            $counts = DB::table('breakout_picks')->whereIn('breakout_id', $ids)->lockForUpdate()->pluck('breakout_id')->countBy();
            $chosen->each(fn (Breakout $b) => $b->setAttribute('picks_count', $counts[$b->id] ?? 0));
            abort_if($chosen->count() !== $ids->count(), 422, 'A chosen breakout does not belong to this event.');

            // A fully registered Attendee keeps the minimum; a draft may still be short.
            $final = $attendee->registration_completed_at !== null;
            if (BreakoutPickRules::picksBreak($chosen, $final ? $min : 0, $max)) {
                abort(422, 'Picks must respect the required count and not overlap.');
            }

            $held = $attendee->breakouts()->pluck('overbooked', 'breakouts.id');
            $full = $ignoreCapacity ? collect() : $chosen->filter(fn (Breakout $b) => $this->isFull($b, $held));
            $flagged = collect();

            if ($full->isNotEmpty()) {
                $flagged = $full->take($this->shortfall(Breakout::query()->where('event_id', $event->id)->withCount('picks')->get(), $chosen, $full, $held, $min));
                $rejected = $full->diff($flagged);

                if ($rejected->isNotEmpty()) {
                    $this->reject($rejected);
                }
            }

            $attendee->breakouts()->sync($chosen->mapWithKeys(fn (Breakout $b) => [
                $b->id => ['overbooked' => $flagged->contains($b) || ($held[$b->id] ?? false)],
            ]));
            $attendee->unsetRelation('breakouts');
            $attendee->forceFill(['picks_saved_at' => now(), 'picks_released_at' => null])->save();

            return $flagged->isEmpty() ? null : 'Opened for you because you have no other choice: '.$flagged->pluck('title')->join(', ').'.';
        });
    }

    private function isFull(Breakout $breakout, Collection $held): bool
    {
        return $breakout->capacity !== null
            && ! $held->has($breakout->id)
            && $breakout->picks_count >= $breakout->capacity;
    }

    /**
     * How many full picks may be let in: the required count minus what open breakouts can still reach.
     */
    private function shortfall(Collection $all, Collection $chosen, Collection $full, Collection $held, int $min): int
    {
        $open = $chosen->diff($full);
        $reachable = $all->diff($chosen)
            ->reject(fn (Breakout $b) => $this->isFull($b, $held) || $open->contains(fn (Breakout $o) => BreakoutPickRules::overlap($b, $o)));

        return max(0, $min - $open->count() - BreakoutPickRules::largestNonOverlapping($reachable));
    }

    private function reject(Collection $rejected): never
    {
        $names = $rejected->map(fn (Breakout $b) => "\"{$b->title}\" is full.")->values();

        throw new HttpResponseException(response()->json([
            'message' => $names->join(' '),
            'errors' => ['breakouts' => $names],
            'full_breakouts' => $rejected->map->only(['id', 'title'])->values(),
        ], 422));
    }
}
