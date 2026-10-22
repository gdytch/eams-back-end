<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\RegistrationCompleteness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:recompute-registration-completeness {--dry-run : Only count the Attendees that would become incomplete}')]
#[Description('Recompute registration completeness for open events (past and archived events are skipped)')]
class RecomputeRegistrationCompleteness extends Command
{
    public function handle(RegistrationCompleteness $completeness): int
    {
        $dryRun = $this->option('dry-run');
        $total = 0;

        Event::withoutGlobalScopes()->open()->each(function (Event $event) use ($completeness, $dryRun, &$total) {
            $count = $dryRun ? $completeness->countBecomingIncomplete($event) : $completeness->recomputeEvent($event);
            $total += $count;
            $this->line("{$event->name}: {$count}");
        });

        $this->info($dryRun ? "This makes {$total} registrants incomplete." : "{$total} registrants became incomplete.");

        return self::SUCCESS;
    }
}
