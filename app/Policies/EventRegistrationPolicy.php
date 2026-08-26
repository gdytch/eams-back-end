<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

class EventRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EventRegistration $eventRegistration): bool
    {
        return ($user->isSuperAdmin() || $user->organization_id === $eventRegistration->event->organization_id)
            && $user->hasAccessToEvent($eventRegistration->event);
    }

    public function create(User $user, Event $event): bool
    {
        return ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }

    public function update(User $user, EventRegistration $eventRegistration): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $eventRegistration->event->organization_id);
    }

    public function delete(User $user, EventRegistration $eventRegistration): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $eventRegistration->event->organization_id);
    }
}
