<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Record the rate for a currency pair, effective from a date.
 *
 * Effective dating means "applies from this date until superseded", so the
 * bookkeeper enters a rate when it moves, not every day. What is recorded here
 * is only ever a *default*: PostEntry takes an explicit per-line rate in
 * preference to this table (D8), and a line that was posted from this table
 * carries its own snapshot afterwards.
 *
 * The rate is carried as a string end to end — from the request, through the
 * NUMERIC(20,10) column, into bcmath in Money::convertTo(). A float round-trip
 * anywhere along that path is exactly the precision loss integer minor units
 * exist to avoid.
 */
final class RecordExchangeRate
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(
        Currency $base,
        Currency $quote,
        string $rate,
        CarbonInterface|string $effectiveOn,
        ?string $source = null,
        ?User $user = null,
    ): ExchangeRate {
        $actor = $user ?? Auth::user();
        $date = Date::parse($effectiveOn)->startOfDay();

        return DB::transaction(function () use ($base, $quote, $rate, $date, $source, $actor): ExchangeRate {
            $exchangeRate = ExchangeRate::query()->create([
                'base_code' => $base->code,
                'quote_code' => $quote->code,
                'rate' => $rate,
                'effective_on' => $date,
                'source' => $source,
                'created_by' => $actor?->id,
            ]);

            $this->audit->handle(
                event: AuditEvent::Created,
                subject: $exchangeRate,
                properties: array_filter([
                    'pair' => "{$base->code}/{$quote->code}",
                    'rate' => $rate,
                    'effective_on' => $date->toDateString(),
                    'source' => $source,
                ], fn (mixed $value): bool => $value !== null),
                description: "Recorded {$base->code}/{$quote->code} rate {$rate} effective {$date->toDateString()}",
                causer: $actor,
                logName: 'exchange-rates',
            );

            return $exchangeRate;
        });
    }
}
