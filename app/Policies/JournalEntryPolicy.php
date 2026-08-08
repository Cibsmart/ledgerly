<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermission(Permission::ViewLedger);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::PostEntries);
    }

    /**
     * Reversal is a separate permission from posting: it corrects something
     * that has already been reported on.
     */
    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermission(Permission::ReverseEntries)
            && $entry->isPosted()
            && ! $entry->isReversed();
    }

    /**
     * Invariant 2. No role can edit or delete a posted entry — the permission
     * does not exist, so there is nothing to grant.
     */
    public function update(User $user, JournalEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return false;
    }
}
