<?php

namespace App\Observers;

use App\Models\Attendee;
use App\Services\AttendeeInvitationService;
use Illuminate\Support\Facades\DB;

class AttendeeObserver
{
    /**
     * The welcome mail goes out once, the moment first_registered_at is set; afterCommit skips a rolled-back Finish.
     */
    public function saved(Attendee $attendee): void
    {
        if ($attendee->wasChanged('first_registered_at') && $attendee->first_registered_at !== null) {
            // A fresh row: afterCommit may run inside this save event, before this instance is synced.
            DB::afterCommit(function () use ($attendee) {
                if ($fresh = Attendee::withoutGlobalScopes()->find($attendee->id)) {
                    app(AttendeeInvitationService::class)->sendWelcome($fresh);
                }
            });
        }
    }
}
