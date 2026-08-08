<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function view(User $user, Account $account): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageAccounts);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasPermission(Permission::ManageAccounts);
    }

    /**
     * Archiving is the only removal the chart supports.
     */
    public function archive(User $user, Account $account): bool
    {
        return $user->hasPermission(Permission::ManageAccounts) && $account->is_active;
    }

    public function restore(User $user, Account $account): bool
    {
        return $user->hasPermission(Permission::ManageAccounts) && ! $account->is_active;
    }

    /**
     * Accounts are never deleted, by anyone.
     *
     * An account carries the meaning of every line posted to it; removing one
     * makes history unreadable even when no line points at it today, because
     * the code may be reused for something else tomorrow. The model refuses it
     * too — this is the layer that stops the button existing.
     */
    public function delete(User $user, Account $account): bool
    {
        return false;
    }
}
