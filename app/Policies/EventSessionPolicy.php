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
        if ($user->isAttendee()) {
            return $user->attendee()->whereHas('registrations', fn ($query) => $query->where('event_id', $eventSession->event_id))->exists();
        }

        return ($user->isSuperAdmin() || $user->organization_id === $eventSession->event->organization_id)
            && $user->hasAccessToEvent($eventSession->event);
    }

    public function viewRoster(User $user, EventSession $eventSession): bool
    {
        return $user->can('viewStaffData', $eventSession->event);
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
