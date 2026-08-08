<?php

namespace App\Support\Ledger;

use App\Support\Currency;
use Carbon\CarbonInterface;

/**
 * A foreign-currency line was posted with no rate to translate it by.
 *
 * Deliberately fatal rather than defaulted. Guessing a rate — yesterday's, or
 * 1 — would produce a functional amount that looks like a real translation and
 * is not, and the error would be invisible in every report thereafter.
 */
final class ExchangeRateNotFoundException extends LedgerException
{
    public static function for(Currency $base, Currency $quote, CarbonInterface $on): self
    {
        return new self(
            "No {$base->code}/{$quote->code} exchange rate is effective on or before "
            ."{$on->toDateString()}. Record the rate, or pass the rate this transaction actually "
            .'executed at as an explicit per-line override.'
        );
    }
}
