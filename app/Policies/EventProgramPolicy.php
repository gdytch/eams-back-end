<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventProgram;
use App\Models\User;

class EventProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EventProgram $program): bool
    {
        return ($user->isSuperAdmin() || $user->organization_id === $program->event->organization_id)
            && $user->hasAccessToEvent($program->event);
    }

    public function create(User $user, Event $event): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id);
    }

    public function update(User $user, EventProgram $program): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $program->event->organization_id);
    }

    public function delete(User $user, EventProgram $program): bool
    {
        return $this->update($user, $program);
    }
}
