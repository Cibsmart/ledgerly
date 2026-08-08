<?php

namespace App\Enums;

/**
 * The discrete things a user can be allowed to do.
 *
 * Permissions are granted through Role::permissions() rather than stored per
 * user. spatie/laravel-permission stays uninstalled until per-user overrides
 * become a real requirement — three roles and a match expression cover the
 * whole build, and policies read from here rather than checking roles directly,
 * so adding a fourth role later is one edit rather than a grep.
 */
enum Permission: string
{
    case ViewLedger = 'view-ledger';
    case ManageAccounts = 'manage-accounts';
    case ManageExchangeRates = 'manage-exchange-rates';
    case PostEntries = 'post-entries';
    case ReverseEntries = 'reverse-entries';
    case ManagePeriods = 'manage-periods';
    case ClosePeriods = 'close-periods';
    case ReopenPeriods = 'reopen-periods';

    public function label(): string
    {
        return match ($this) {
            self::ViewLedger => 'View the ledger and reports',
            self::ManageAccounts => 'Create, edit and archive accounts',
            self::ManageExchangeRates => 'Maintain exchange rates',
            self::PostEntries => 'Post journal entries',
            self::ReverseEntries => 'Reverse posted entries',
            self::ManagePeriods => 'Create and edit fiscal periods',
            self::ClosePeriods => 'Close a fiscal period',
            self::ReopenPeriods => 'Reopen a closed fiscal period',
        };
    }
}
