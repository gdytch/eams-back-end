<?php

namespace App\Policies;

use App\Models\Breakout;
use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

class BreakoutPolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Breakout $breakout): bool
    {
        return $user->can('viewStaffData', $breakout->event);
    }

    public function create(User $user, Event $event): Response|bool
    {
        return $this->allowWrite(
            $user->isSuperAdmin() || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id),
            $event,
        );
    }

    public function update(User $user, Breakout $breakout): Response|bool
    {
        return $this->create($user, $breakout->event);
    }

    public function delete(User $user, Breakout $breakout): Response|bool
    {
        return $this->update($user, $breakout);
    }
}
