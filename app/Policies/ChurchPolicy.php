<?php

namespace App\Policies;

use App\Models\Church;
use App\Models\User;

class ChurchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Church $church): bool
    {
        return $user->isSuperAdmin() || $user->organization_id === $church->organization_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isOrgAdmin() || $user->isAttendee();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Church $church): bool
    {
        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $church->organization_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Church $church): bool
    {
        return $this->update($user, $church);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Church $church): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Church $church): bool
    {
        return false;
    }
}
