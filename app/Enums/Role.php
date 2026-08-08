<?php

namespace App\Enums;

/**
 * What a user is allowed to do with the books.
 *
 * Three roles, modelled as an enum with a permission match rather than through
 * spatie/laravel-permission. The distinction that matters most is the last one
 * in Bookkeeper's list: a bookkeeper closes periods but cannot reopen one.
 * Closing is routine month-end work; reopening unlocks a period that has been
 * reported on, and belongs to whoever owns the books.
 */
enum Role: string
{
    case Admin = 'admin';
    case Bookkeeper = 'bookkeeper';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Bookkeeper => 'Bookkeeper',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access, including reopening closed periods.',
            self::Bookkeeper => 'Day-to-day bookkeeping: posts, reverses and closes periods.',
            self::Viewer => 'Read-only access to the ledger and reports.',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),

            self::Bookkeeper => [
                Permission::ViewLedger,
                Permission::ManageAccounts,
                Permission::ManageExchangeRates,
                Permission::PostEntries,
                Permission::ReverseEntries,
                Permission::ManagePeriods,
                Permission::ClosePeriods,
            ],

            self::Viewer => [
                Permission::ViewLedger,
            ],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
