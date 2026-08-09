<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Remove a rate entered in error.
 *
 * Unlike an account, a rate really is deletable, and for the same reason it is
 * correctable: nothing points at it. A posted line holds its own snapshot, so
 * removing a row here cannot orphan history — the worst it can do is leave a
 * future posting with no default, which PostEntry reports as a missing rate
 * rather than silently guessing one.
 *
 * The full row goes into the audit properties, because after this runs the
 * trail is the only remaining record that the rate was ever entered.
 */
final class DeleteExchangeRate
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(ExchangeRate $exchangeRate, ?User $user = null): void
    {
        $actor = $user ?? Auth::user();

        DB::transaction(function () use ($exchangeRate, $actor): void {
            $pair = "{$exchangeRate->base_code}/{$exchangeRate->quote_code}";
            $effectiveOn = $exchangeRate->effective_on->toDateString();

            // Audited before the delete: RecordAudit needs the subject to still
            // have a key to point at.
            $this->audit->handle(
                event: AuditEvent::Deleted,
                subject: $exchangeRate,
                properties: array_filter([
                    'pair' => $pair,
                    'rate' => $exchangeRate->rate,
                    'effective_on' => $effectiveOn,
                    'source' => $exchangeRate->source,
                ], fn (mixed $value): bool => $value !== null),
                description: "Deleted {$pair} rate effective {$effectiveOn}",
                causer: $actor,
                logName: 'exchange-rates',
            );

            $exchangeRate->delete();
        });
    }
}
