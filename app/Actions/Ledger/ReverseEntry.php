<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Ledger\ClosedPeriodException;
use App\Support\Ledger\EntryNotReversibleException;
use App\Support\Ledger\PendingLine;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only way to correct a posted entry.
 *
 * Invariant 2 says a posted entry is never edited or deleted, so a correction
 * is a second entry that cancels the first and a third that records what should
 * have happened. This action writes the second.
 *
 * **The reversal reuses each original line's snapshotted rate.** It must cancel
 * the original exactly in functional terms, and re-resolving today's rate would
 * leave a residual that is indistinguishable from a real FX movement. That is
 * also why the reversal is built from the stored lines rather than from
 * whatever produced them.
 */
final class ReverseEntry
{
    public function __construct(
        private readonly PostEntry $postEntry,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  CarbonInterface|string|null  $reversalDate  Defaults to the original entry
     *                                                     date. Must fall in an open
     *                                                     period: if the original's period
     *                                                     has been closed, the correction
     *                                                     belongs in a later one.
     */
    public function handle(
        JournalEntry $original,
        CarbonInterface|string|null $reversalDate = null,
        ?string $memo = null,
        ?User $user = null,
    ): JournalEntry {
        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($original, $reversalDate, $memo, $actor): JournalEntry {
            $original->loadMissing(['lines', 'period']);

            if (! $original->isPosted()) {
                throw EntryNotReversibleException::notPosted($original);
            }

            $existing = $original->reversal()->first();

            if ($existing !== null) {
                throw EntryNotReversibleException::alreadyReversed($original, $existing);
            }

            $date = $reversalDate ?? $original->entry_date;

            // Reversing into the original's own period is the common case and
            // keeps the correction in the month it belongs to. Once that period
            // is closed the caller has to choose a later one — silently sliding
            // the date forward would move the correction to a month the user
            // did not pick.
            if ($reversalDate === null && $original->period->isClosed()) {
                throw ClosedPeriodException::cannotReverseInto($original->period);
            }

            $reversal = $this->postEntry->handle(
                entryDate: $date,
                lines: $this->mirror($original),
                memo: $memo ?? "Reversal of {$original->entry_number}",
                reference: $original->reference,
                user: $actor,
                source: $original->source_type === null ? null : $original->source,
                reverses: $original,
            );

            // Recorded against the original, whose history is what changed. The
            // new entry gets its own Posted event from PostEntry.
            $this->audit->handle(
                event: AuditEvent::Reversed,
                subject: $original,
                properties: [
                    'entry_number' => $original->entry_number,
                    'reversed_by_entry_number' => $reversal->entry_number,
                    'reversal_date' => $reversal->entry_date->toDateString(),
                    'reason' => $memo,
                ],
                description: "Reversed journal entry {$original->entry_number} "
                    ."with {$reversal->entry_number}",
                causer: $actor,
                logName: 'ledger',
            );

            return $reversal;
        });
    }

    /**
     * Each original line, negated, at the rate it was posted with.
     *
     * Money::convertTo() rounds half away from zero, which is symmetric, so
     * negating the transaction amount and reusing the rate reproduces exactly
     * the negated functional amount. The reversal therefore cancels the
     * original to the minor unit rather than to within a rounding error.
     *
     * @return list<PendingLine>
     */
    private function mirror(JournalEntry $original): array
    {
        $lines = [];

        foreach ($original->lines as $line) {
            /** @var JournalLine $line */
            $lines[] = PendingLine::signed(
                account: $line->account_id,
                amount: $line->amount_minor->negate(),
                exchangeRate: $line->exchange_rate,
                memo: $line->memo,
            );
        }

        return $lines;
    }
}
