<?php

namespace App\Enums;

/**
 * Where an account's movements land on the cash flow statement.
 *
 * Populated on every account from Phase 1 even though the statement itself is
 * Phase 10: adding this later means re-classifying the whole chart by hand,
 * and a half-classified chart produces a cash flow statement that silently
 * omits whatever was missed.
 *
 * Null on an account means "not classified" — correct for the cash and bank
 * balances, which are what the statement reconciles *to* rather than movements
 * within it, and for pure header accounts that are never posted to.
 */
enum CashFlowClassification: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operating',
            self::Investing => 'Investing',
            self::Financing => 'Financing',
        };
    }
}
