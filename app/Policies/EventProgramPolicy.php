<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\EventProgram;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

class EventProgramPolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EventProgram $program): Response|bool
    {
        if ($program->event->isHiddenFrom($user)) {
            return Response::denyAsNotFound();
        }

        return ($user->isSuperAdmin() || $user->organization_id === $program->event->organization_id)
            && $user->hasAccessToEvent($program->event);
    }

    public function create(User $user, Event $event): Response|bool
    {
        return $this->allowWrite(
            $user->isSuperAdmin() || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id),
            $event,
        );
    }

    public function update(User $user, EventProgram $program): Response|bool
    {
        return $this->create($user, $program->event);
    }

    public function delete(User $user, EventProgram $program): Response|bool
    {
        return $this->update($user, $program);
    }
}
