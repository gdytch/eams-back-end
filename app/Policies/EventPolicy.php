<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

class EventPolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        if ($user->isAttendee()) {
            return $event->status->value === 'published'
                && $user->attendees()->withoutGlobalScopes()->where('event_id', $event->id)->exists();
        }

        return ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin();
    }

    public function update(User $user, Event $event): Response|bool
    {
        return $this->allowWrite($this->viewSpeakers($user, $event), $event);
    }

    /**
     * Read-only speaker management view; allowed on archived events.
     */
    public function viewSpeakers(User $user, Event $event): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id);
    }

    /**
     * Self-registration through the public invite link.
     */
    public function register(User $user, Event $event): Response|bool
    {
        return $this->allowWrite(true, $event);
    }

    /**
     * Newly granting a checker access to the event; who may manage access is UserPolicy::manageEventAccess.
     */
    public function grantAccess(User $user, Event $event): Response|bool
    {
        return $this->allowWrite(true, $event);
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

    public function viewStaffData(User $user, Event $event): bool
    {
        return ($user->isSuperAdmin() || $user->isOrgAdmin() || $user->isChecker())
            && ($user->isSuperAdmin() || $user->organization_id === $event->organization_id)
            && $user->hasAccessToEvent($event);
    }
}
