<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): Response|bool
    {
        if ($user->isAttendee()) {
            return $event->status->value === 'published'
                && $user->attendee()->whereHas('registrations', fn ($query) => $query->where('event_id', $event->id))->exists();
        }

        if ($event->isHiddenFrom($user)) {
            return Response::denyAsNotFound();
        }

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

    public function archive(User $user, Event $event): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageIdCards(User $user, Event $event): bool
    {
        return ($user->isSuperAdmin() || (($user->isOrgAdmin() || $user->isChecker()) && $user->organization_id === $event->organization_id))
            && $user->hasAccessToEvent($event);
    }

    public function viewStaffData(User $user, Event $event): Response|bool
    {
        if ($event->isHiddenFrom($user)) {
            return Response::denyAsNotFound();
        }

        return ($user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker())
            && ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }
}
