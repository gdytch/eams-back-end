<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Form;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

/**
 * Authoring is for org_admin of the event's organization and super_admin. Checkers have no access.
 */
class FormPolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function view(User $user, Form $form): bool
    {
        return $this->manages($user, $form->event);
    }

    public function create(User $user, Event $event): Response|bool
    {
        return $this->allowWrite($this->manages($user, $event), $event);
    }

    public function update(User $user, Form $form): Response|bool
    {
        return $this->create($user, $form->event);
    }

    public function delete(User $user, Form $form): Response|bool
    {
        return $this->update($user, $form);
    }

    private function manages(User $user, Event $event): bool
    {
        return $user->isSuperAdmin() || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id);
    }
}
