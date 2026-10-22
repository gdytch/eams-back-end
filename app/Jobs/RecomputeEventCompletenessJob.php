<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\RegistrationCompleteness;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recomputes completeness for every Attendee of one event, after an admin change that tightens the rules.
 */
class RecomputeEventCompletenessJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event) {}

    public function handle(RegistrationCompleteness $completeness): void
    {
        $completeness->recomputeEvent($this->event);
    }
}
