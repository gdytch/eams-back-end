<?php

namespace App\Policies;

use App\Models\Mission;
use App\Models\User;

class MissionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Mission $mission): bool
    {
        return $user->isSuperAdmin() || $user->organization_id === $mission->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin();
    }

    public function update(User $user, Mission $mission): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $mission->organization_id);
    }

    public function delete(User $user, Mission $mission): bool
    {
        return $this->update($user, $mission);
    }
}
