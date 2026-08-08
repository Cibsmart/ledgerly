<?php

namespace App\Enums;

/**
 * The side an account's balance naturally falls on.
 *
 * This is never stored. It is a pure function of AccountType, and a column
 * holding it would be a second source of truth that can drift out of step with
 * the type it is derived from.
 *
 * Lines carry a single signed amount (debit positive), so this drives
 * presentation and variance reporting rather than storage: an Asset with a
 * negative balance is a credit balance in an account that normally debits,
 * which is worth flagging on a report but is not in itself an error.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }

    /**
     * The sign a balance on this side carries, given debit-positive storage.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Debit => 1,
            self::Credit => -1,
        };
    }
}
