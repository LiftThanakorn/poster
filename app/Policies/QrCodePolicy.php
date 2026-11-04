<?php

namespace App\Policies;

use App\Models\QrCode;
use App\Models\User;

class QrCodePolicy
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
    public function view(User $user, QrCode $qrCode): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, QrCode $qrCode): bool
    {
        return $user->id === $qrCode->user_id || ($user->role ?? null) === 'super_admin';
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, QrCode $qrCode): bool
    {
        return $user->id === $qrCode->user_id || ($user->role ?? null) === 'super_admin';
    }
}
