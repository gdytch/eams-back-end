<?php

namespace App\Console\Commands;

use App\Models\Attendee;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:release-stale-draft-picks')]
#[Description('Drop the breakout picks of Draft Attendees whose last save is over 24 hours old')]
class ReleaseStaleDraftPicks extends Command
{
    public function handle(): int
    {
        $released = 0;

        Attendee::withoutGlobalScopes()->whereNull('first_registered_at')->where('picks_saved_at', '<', now()->subDay())
            ->whereHas('breakouts')->pluck('id')->chunk(500)->each(function ($chunk) use (&$released) {
                // Re-check under lock: a save between the select and here keeps its picks.
                DB::transaction(function () use ($chunk, &$released) {
                    $ids = DB::table('attendees')->whereIn('id', $chunk)->whereNull('first_registered_at')
                        ->where('picks_saved_at', '<', now()->subDay())->lockForUpdate()->pluck('id');
                    DB::table('breakout_picks')->whereIn('attendee_id', $ids)->delete();
                    DB::table('attendees')->whereIn('id', $ids)->update(['picks_released_at' => now(), 'picks_saved_at' => null]);
                    $released += $ids->count();
                });
            });

        $this->info("Released picks of {$released} draft attendees.");

        return self::SUCCESS;
    }
}
