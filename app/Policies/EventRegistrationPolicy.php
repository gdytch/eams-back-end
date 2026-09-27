<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

class EventRegistrationPolicy
{
    public function viewAny(User $user, Event $event): bool
    {
        return $user->can('viewStaffData', $event);
    }

    public function view(User $user, EventRegistration $eventRegistration): bool
    {
        if ($user->isAttendee()) {
            return $eventRegistration->attendee?->user_id === $user->id;
        }

        return ($user->isSuperAdmin() || $user->organization_id === $eventRegistration->event->organization_id)
            && $user->hasAccessToEvent($eventRegistration->event);
    }

    public function create(User $user, Event $event): bool
    {
        return ($user->isSuperAdmin() || ($user->isOrgAdmin() || $user->isChecker()) && $user->organization_id === $event->organization_id)
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

    public function downloadIdCard(User $user, EventRegistration $eventRegistration): bool
    {
        if ($user->isAttendee()) {
            return $eventRegistration->attendee?->user_id === $user->id;
        }

        return ($user->isSuperAdmin() || $user->organization_id === $eventRegistration->event->organization_id)
            && $user->hasAccessToEvent($eventRegistration->event);
    }

    public function viewIdCardImage(User $user, EventRegistration $eventRegistration): bool
    {
        if ($user->isAttendee()) {
            return $eventRegistration->attendee?->user_id === $user->id;
        }

        return ($user->isSuperAdmin() || $user->organization_id === $eventRegistration->event->organization_id)
            && $user->hasAccessToEvent($eventRegistration->event);
    }

    public function manageIdCards(User $user, EventRegistration $eventRegistration): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin()
                && $user->organization_id === $eventRegistration->event->organization_id
                && $user->hasAccessToEvent($eventRegistration->event));
    }

    public function viewStaffData(User $user, EventRegistration $eventRegistration): bool
    {
        return $user->can('viewStaffData', $eventRegistration->event);
    }
}
