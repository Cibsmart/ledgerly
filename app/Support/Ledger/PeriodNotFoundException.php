<?php

namespace App\Support\Ledger;

use Carbon\CarbonInterface;

/**
 * No fiscal period covers the entry date.
 *
 * A gap in the period calendar is a safe failure — nothing can be posted into
 * it — but it is still a failure, and the fix is to create the period rather
 * than to move the entry.
 */
final class PeriodNotFoundException extends LedgerException
{
    public static function for(CarbonInterface $entryDate): self
    {
        return new self(
            "No fiscal period covers {$entryDate->toDateString()}. Create the period before "
            .'posting into it — an entry date always determines its period, and moving the date '
            .'to fit an existing period would put the entry in the wrong month.'
        );
    }

    public static function overlapping(CarbonInterface $start, CarbonInterface $end): self
    {
        return new self(
            "A period already covers part of {$start->toDateString()} to {$end->toDateString()}. "
            .'Periods must not overlap, or an entry date would resolve to more than one of them.'
        );
    }
}
