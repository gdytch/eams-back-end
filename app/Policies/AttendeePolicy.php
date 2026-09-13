<?php

namespace App\Policies;

use App\Models\Attendee;
use App\Models\User;

class AttendeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();
    }

    public function view(User $user, Attendee $attendee): bool
    {
        return $user->isSuperAdmin() || $user->organization_id === $attendee->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker();
    }

    public function update(User $user, Attendee $attendee): bool
    {
        if ($user->isAttendee()) {
            return $attendee->user_id === $user->id;
        }

        return $this->view($user, $attendee);
    }

    public function delete(User $user, Attendee $attendee): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $attendee->organization_id);
    }
}
