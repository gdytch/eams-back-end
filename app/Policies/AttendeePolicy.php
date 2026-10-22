<?php

namespace App\Policies;

use App\Models\Attendee;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

/**
 * Attendees are organization-scoped until Release 1, so archived-event write blocks
 * are approximated through the attendee's registrations.
 */
class AttendeePolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();
    }

    public function view(User $user, Attendee $attendee): bool
    {
        return $user->isSuperAdmin()
            || ($user->isAttendee() && $attendee->user_id === $user->id)
            || ((! $user->isAttendee()) && $user->organization_id === $attendee->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();
    }

    /**
     * Denied once every registration of the attendee is in an archived event.
     */
    public function update(User $user, Attendee $attendee): Response|bool
    {
        $allowed = $user->isAttendee()
            ? $attendee->user_id === $user->id
            : $user->isSuperAdmin() || $user->organization_id === $attendee->organization_id;

        if (! $allowed) {
            return false;
        }

        $archivedCount = $attendee->registrationsInArchivedEvents()->count();

        return $archivedCount > 0 && $archivedCount === $attendee->registrations()->count()
            ? $this->archivedDenial()
            : true;
    }

    /**
     * Denied when any registration is in an archived event, since deleting would destroy its history.
     */
    public function delete(User $user, Attendee $attendee): Response|bool
    {
        if (! $user->isSuperAdmin() && ! ($user->isOrgAdmin() && $user->organization_id === $attendee->organization_id)) {
            return false;
        }

        return $attendee->registrationsInArchivedEvents()->exists() ? $this->archivedDenial() : true;
    }

    /**
     * Merging as primary or as duplicate source, denied when any registration is in an archived event.
     */
    public function merge(User $user, Attendee $attendee): Response|bool
    {
        return $this->delete($user, $attendee);
    }
}
