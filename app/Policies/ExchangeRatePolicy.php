<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function view(User $user, ExchangeRate $rate): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageExchangeRates);
    }

    /**
     * Correcting a rate is permitted, and changes only what future postings
     * default to: posted lines carry their own snapshot and never re-read this
     * table, so no history moves.
     */
    public function update(User $user, ExchangeRate $rate): bool
    {
        return $user->hasPermission(Permission::ManageExchangeRates);
    }

    public function delete(User $user, ExchangeRate $rate): bool
    {
        return $user->hasPermission(Permission::ManageExchangeRates);
    }
}
