<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        return ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin();
    }

    public function update(User $user, Event $event): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }

    public function manageIdCards(User $user, Event $event): bool
    {
        return ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }
}
