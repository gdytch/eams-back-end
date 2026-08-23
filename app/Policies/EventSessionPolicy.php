<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventSession;
use App\Models\User;

class EventSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EventSession $eventSession): bool
    {
        return ($user->isSuperAdmin() || $user->organization_id === $eventSession->event->organization_id)
            && $user->hasAccessToEvent($eventSession->event);
    }

    public function create(User $user, Event $event): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id);
    }

    public function update(User $user, EventSession $eventSession): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $eventSession->event->organization_id);
    }

    public function delete(User $user, EventSession $eventSession): bool
    {
        return $this->update($user, $eventSession);
    }
}
