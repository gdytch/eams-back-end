<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin();
    }

    public function view(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $model->organization_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin();
    }

    public function update(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $model->organization_id && $model->role !== UserRole::SuperAdmin);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }
}
