<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\FormAnswer;
use App\Models\User;

/**
 * An uploaded answer file is private to its Attendee, the org_admin of the event's organization and super_admin.
 */
class FormAnswerPolicy
{
    public function download(User $user, FormAnswer $answer): bool
    {
        $attendee = $answer->submission->attendee()->withoutGlobalScopes()->first();
        $event = $attendee === null ? null : Event::withoutGlobalScopes()->find($attendee->event_id);
        if ($attendee === null || $event === null) {
            return false;
        }

        if ($user->isAttendee()) {
            return $attendee->user_id === $user->id;
        }

        return $user->isSuperAdmin() || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id && $user->hasAccessToEvent($event));
    }
}
