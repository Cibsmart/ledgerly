<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Period;
use App\Models\User;

class PeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function view(User $user, Period $period): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManagePeriods);
    }

    public function update(User $user, Period $period): bool
    {
        return $user->hasPermission(Permission::ManagePeriods) && $period->isOpen();
    }

    public function close(User $user, Period $period): bool
    {
        return $user->hasPermission(Permission::ClosePeriods) && $period->isOpen();
    }

    /**
     * Reopening is administrator-only, and the split from close() is the point.
     *
     * Closing is routine month-end work a bookkeeper does. Reopening unlocks a
     * period that has been reported on, so anything posted afterwards changes
     * figures somebody has already seen.
     */
    public function reopen(User $user, Period $period): bool
    {
        return $user->hasPermission(Permission::ReopenPeriods) && $period->isClosed();
    }

    /**
     * Deleting a period would orphan every entry assigned to it.
     */
    public function delete(User $user, Period $period): bool
    {
        return false;
    }
}
