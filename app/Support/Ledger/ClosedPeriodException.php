<?php

namespace App\Support\Ledger;

use App\Models\Period;
use Carbon\CarbonInterface;

/**
 * Invariant 6: closed periods are closed.
 */
final class ClosedPeriodException extends LedgerException
{
    public static function cannotPostInto(Period $period, CarbonInterface $entryDate): self
    {
        return new self(
            "Cannot post an entry dated {$entryDate->toDateString()} into [{$period->name}], "
            .'which was closed on '.($period->closed_at?->toDateString() ?? 'an unknown date').'. '
            .'Post the correction into a later open period instead.'
        );
    }

    public static function cannotReverseInto(Period $period): self
    {
        return new self(
            "The original entry's period [{$period->name}] is closed, so the reversal cannot be "
            .'dated into it. Supply a reversal date that falls in a later open period.'
        );
    }

    public static function alreadyClosed(Period $period): self
    {
        return new self("Period [{$period->name}] is already closed.");
    }

    public static function notClosed(Period $period): self
    {
        return new self("Period [{$period->name}] is not closed, so it cannot be reopened.");
    }
}
