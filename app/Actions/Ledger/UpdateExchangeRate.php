<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Correct a recorded rate.
 *
 * This is permitted, and it is invariant 2 that makes it safe: a posted line
 * snapshots the rate it was translated at and never reads this table again, so
 * correcting a rate here changes what *future* postings default to and nothing
 * whatsoever about history. The before/after pair goes into the audit trail
 * because "the rate was wrong for three weeks" is a question someone will
 * eventually ask about a stretch of entries.
 *
 * The pair itself is not editable. Changing `base_code` or `quote_code` would
 * turn a correction into a different rate wearing the old one's identity —
 * delete the row and record the right one instead.
 */
final class UpdateExchangeRate
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(
        ExchangeRate $exchangeRate,
        string $rate,
        string $effectiveOn,
        ?string $source = null,
        ?User $user = null,
    ): ExchangeRate {
        $actor = $user ?? Auth::user();
        $date = Date::parse($effectiveOn)->startOfDay();

        return DB::transaction(function () use ($exchangeRate, $rate, $date, $source, $actor): ExchangeRate {
            $before = [
                'rate' => $exchangeRate->rate,
                'effective_on' => $exchangeRate->effective_on->toDateString(),
                'source' => $exchangeRate->source,
            ];

            $exchangeRate->fill([
                'rate' => $rate,
                'effective_on' => $date,
                'source' => $source,
            ])->save();

            $this->audit->handle(
                event: AuditEvent::Updated,
                subject: $exchangeRate,
                properties: [
                    'pair' => "{$exchangeRate->base_code}/{$exchangeRate->quote_code}",
                    'before' => $before,
                    'after' => [
                        'rate' => $rate,
                        'effective_on' => $date->toDateString(),
                        'source' => $source,
                    ],
                ],
                description: "Corrected {$exchangeRate->base_code}/{$exchangeRate->quote_code} rate "
                    ."effective {$date->toDateString()}",
                causer: $actor,
                logName: 'exchange-rates',
            );

            return $exchangeRate->refresh();
        });
    }
}
