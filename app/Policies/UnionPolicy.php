<?php

namespace App\Policies;

use App\Models\Union;
use App\Models\User;

class UnionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Union $union): bool
    {
        return $user->isSuperAdmin() || $user->organization_id === $union->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin();
    }

    public function update(User $user, Union $union): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $union->organization_id);
    }

    public function delete(User $user, Union $union): bool
    {
        return $this->update($user, $union);
    }
}
