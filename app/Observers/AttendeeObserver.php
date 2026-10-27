<?php

namespace App\Observers;

use App\Models\Attendee;
use App\Services\AttendeeInvitationService;
use App\Services\CertificateAudienceSync;
use Illuminate\Support\Facades\DB;

class AttendeeObserver
{
    /**
     * The welcome mail goes out once, the moment first_registered_at is set; afterCommit skips a rolled-back Finish.
     */
    public function saved(Attendee $attendee): void
    {
        $nameChanged = $attendee->wasChanged(['first_name', 'middle_name', 'last_name']);
        if (! $attendee->wasRecentlyCreated || $attendee->registration_completed_at !== null) {
            // Fully registered, Territory or name changes move an Attendee in or out of a published Certificate's Audience.
            if ($nameChanged || $attendee->wasChanged(['registration_completed_at', 'organization_level', 'union_id', 'mission_id'])) {
                app(CertificateAudienceSync::class)->sync($attendee, $nameChanged);
            }
        }

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
