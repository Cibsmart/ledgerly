<?php

namespace App\Support\Ledger;

use App\Models\ExchangeRate;
use App\Support\Currency;
use Carbon\CarbonInterface;

/**
 * Decides the rate a line will be posted at.
 *
 * D8, in one place: an explicit rate wins, because a USD→NGN conversion inside
 * Grey executes at a specific negotiated rate and that is what belongs on the
 * line; the `exchange_rates` table is the fallback for when nothing was quoted;
 * a pair that is already functional is 1 rather than null, so every line
 * translates the same way and reports need no special case.
 *
 * This exists as its own class because PostEntry is not the only caller that
 * needs the answer. An action that must produce a balancing line in the
 * functional currency — opening balances against 3200, an own-account
 * conversion's FX residual (D9) — has to translate its lines *before* it calls
 * PostEntry, and then hand PostEntry the same rates as explicit overrides. If
 * that second implementation resolved rates even slightly differently, the
 * residual it computed would not be the residual PostEntry sees, and the entry
 * would fail the balance assertion for reasons no error message would explain.
 */
final class ExchangeRateResolver
{
    /**
     * The rate to snapshot for a conversion from one currency to another.
     *
     * @param  string|null  $explicit  The rate this transaction actually
     *                                 executed at, where one is known.
     */
    public function resolve(
        Currency $from,
        Currency $to,
        ?string $explicit,
        CarbonInterface $date,
    ): string {
        if ($from->equals($to)) {
            return '1';
        }

        if ($explicit !== null) {
            return $explicit;
        }

        $rate = ExchangeRate::resolve($from, $to, $date);

        if ($rate === null) {
            throw ExchangeRateNotFoundException::for($from, $to, $date);
        }

        return $rate->rate;
    }
}
