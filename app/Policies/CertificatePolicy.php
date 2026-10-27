<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\Event;
use App\Models\User;
use App\Policies\Concerns\DeniesArchivedEvents;
use Illuminate\Auth\Access\Response;

/** Certificates are managed by super admins and the admins of the event's organization only. */
class CertificatePolicy
{
    use DeniesArchivedEvents;

    public function viewAny(User $user, Event $event): bool
    {
        return $this->manages($user, $event);
    }

    public function view(User $user, Certificate $certificate): bool
    {
        return $this->manages($user, $certificate->event);
    }

    public function create(User $user, Event $event): Response|bool
    {
        return $this->allowWrite($this->manages($user, $event), $event);
    }

    public function update(User $user, Certificate $certificate): Response|bool
    {
        return $this->create($user, $certificate->event);
    }

    public function delete(User $user, Certificate $certificate): Response|bool
    {
        return $this->update($user, $certificate);
    }

    private function manages(User $user, Event $event): bool
    {
        return $user->isSuperAdmin() || ($user->isOrgAdmin() && $user->organization_id === $event->organization_id);
    }
}
