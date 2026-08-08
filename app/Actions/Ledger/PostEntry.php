<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Models\User;
use App\Support\Currency;
use App\Support\Ledger\ClosedPeriodException;
use App\Support\Ledger\ExchangeRateNotFoundException;
use App\Support\Ledger\InvalidLineException;
use App\Support\Ledger\PendingLine;
use App\Support\Ledger\PeriodNotFoundException;
use App\Support\Ledger\UnbalancedEntryException;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The one way a journal entry enters the books.
 *
 * Controllers, jobs, commands, seeders, factories and tests all come through
 * here. Nothing else builds `journal_lines`, because balance validation,
 * period resolution, rate snapshotting and attribution all live in this method
 * and every one of them is skippable by a direct insert.
 *
 * Order matters. The period is locked before anything is written, so a close
 * running concurrently either happens entirely before this posting (and this
 * posting is rejected) or entirely after it (and the closed period contains
 * this entry). Balance is asserted before the first row is inserted, so an
 * unbalanced entry never reaches the database — PostgreSQL's deferred
 * constraint is the backstop, not the mechanism.
 */
final class PostEntry
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  list<PendingLine>  $lines
     * @param  Model|null  $source  What produced this entry — a payroll run, an
     *                              import batch, a transfer. Principle 7: every
     *                              subsidiary module tags its provenance.
     * @param  JournalEntry|null  $reverses  Set only by ReverseEntry.
     * @param  string|null  $batchKey  Groups the postings of one run (a payroll
     *                                 month, a depreciation run) in the audit
     *                                 trail. activitylog v5 dropped LogBatch, so
     *                                 the grouping key is ours to carry.
     */
    public function handle(
        CarbonInterface|string $entryDate,
        array $lines,
        ?string $memo = null,
        ?string $reference = null,
        ?User $user = null,
        ?Model $source = null,
        ?JournalEntry $reverses = null,
        ?string $batchKey = null,
    ): JournalEntry {
        $date = $this->normalizeDate($entryDate);
        $actor = $user ?? Auth::user();

        if (count($lines) < 2) {
            throw UnbalancedEntryException::tooFewLines(count($lines));
        }

        return DB::transaction(function () use (
            $date, $lines, $memo, $reference, $actor, $source, $reverses, $batchKey
        ): JournalEntry {
            $period = $this->resolvePeriod($date);
            $accounts = $this->resolveAccounts($lines);

            $translated = $this->translate($lines, $accounts, $date);

            $this->assertBalanced($translated);

            $entry = new JournalEntry;
            $entry->entry_number = $this->allocateEntryNumber($period->fiscal_year);
            $entry->entry_date = $date;
            $entry->period_id = $period->id;
            $entry->memo = $memo;
            $entry->reference = $reference;
            $entry->status = JournalEntryStatus::Posted;
            $entry->created_by = $actor?->id;
            $entry->posted_by = $actor?->id;
            $entry->posted_at = Date::now();
            $entry->reverses_journal_entry_id = $reverses?->id;

            if ($source !== null) {
                $entry->source()->associate($source);
            }

            $entry->save();

            // Assigned attribute by attribute rather than mass-assigned:
            // JournalLine declares no fillable attributes at all, because
            // nothing outside this method may write one, and a fillable list
            // is an invitation for something to try.
            //
            // Assigning the Money value directly lets MoneyCast write the
            // amount and its currency together — the pair can never come apart.
            foreach ($translated as $line) {
                $model = new JournalLine;
                $model->journal_entry_id = $entry->id;
                $model->account_id = $line['account_id'];
                $model->amount_minor = $line['amount'];
                $model->exchange_rate = $line['rate'];
                $model->functional_amount_minor = $line['functional'];
                $model->memo = $line['memo'];
                $model->save();
            }

            // Inside the transaction on purpose: an audit row that survives a
            // rolled-back posting describes something that never happened.
            // Always Posted, even for a reversal: what happened *here* is that
            // an entry was posted. ReverseEntry records the Reversed event
            // against the original, which is the entry whose history changed.
            $this->audit->handle(
                event: AuditEvent::Posted,
                subject: $entry,
                properties: array_filter([
                    'entry_number' => $entry->entry_number,
                    'entry_date' => $date->toDateString(),
                    'period' => $period->name,
                    'line_count' => count($translated),
                    'total_debits_minor' => $this->totalDebits($translated)->minor,
                    'functional_currency' => Currency::functional()->code,
                    'reverses_entry_number' => $reverses?->entry_number,
                    'source_type' => $entry->source_type,
                    'source_id' => $entry->source_id,
                    'batch_key' => $batchKey,
                ], fn (mixed $value): bool => $value !== null),
                description: $reverses === null
                    ? "Posted journal entry {$entry->entry_number}"
                    : "Posted journal entry {$entry->entry_number} reversing {$reverses->entry_number}",
                causer: $actor,
                logName: 'ledger',
            );

            return $entry;
        });
    }

    private function normalizeDate(CarbonInterface|string $entryDate): CarbonInterface
    {
        return Date::parse($entryDate)->startOfDay();
    }

    /**
     * Resolve and lock the period the entry date falls in.
     *
     * The lock is the answer to the close-versus-post race: ClosePeriod takes
     * the same row lock, so the two serialise rather than interleaving into a
     * posting that lands in a period closed a microsecond earlier.
     */
    private function resolvePeriod(CarbonInterface $date): Period
    {
        $period = Period::query()
            ->containing($date)
            ->lockForUpdate()
            ->first();

        if ($period === null) {
            throw PeriodNotFoundException::for($date);
        }

        if ($period->isClosed()) {
            throw ClosedPeriodException::cannotPostInto($period, $date);
        }

        return $period;
    }

    /**
     * @param  list<PendingLine>  $lines
     * @return Collection<int, Account>
     */
    private function resolveAccounts(array $lines): Collection
    {
        $ids = array_values(array_unique(array_map(
            fn (PendingLine $line): int => $line->accountId,
            $lines
        )));

        /** @var Collection<int, Account> $accounts */
        $accounts = Account::query()
            ->withCount('children')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $accounts->has($id)) {
                throw InvalidLineException::unknownAccount($id);
            }
        }

        return $accounts;
    }

    /**
     * Snapshot each line's rate and translate it into the functional currency.
     *
     * @param  list<PendingLine>  $lines
     * @param  Collection<int, Account>  $accounts
     * @return list<array{account_id: int, currency_code: string, amount: Money, rate: string, functional: Money, memo: string|null}>
     */
    private function translate(array $lines, Collection $accounts, CarbonInterface $date): array
    {
        $functional = Currency::functional();
        $translated = [];

        foreach ($lines as $line) {
            /** @var Account $account */
            $account = $accounts->get($line->accountId);

            if (! $account->is_active) {
                throw InvalidLineException::archivedAccount($account);
            }

            if (! $account->isPostable()) {
                throw InvalidLineException::headerAccount($account);
            }

            $currency = $line->amount->currency;

            if (! $account->accepts($currency)) {
                throw InvalidLineException::currencyMismatch($account, $currency);
            }

            $rate = $this->resolveRate($account, $currency, $functional, $line->exchangeRate, $date);

            $amount = $line->amount->convertTo($functional, $rate);

            // A nonzero line that translates to nothing contributes nothing to
            // the trial balance, and would trip the sign-agreement constraint.
            // Catch it here so the message names the rate rather than a
            // constraint.
            if ($amount->isZero()) {
                throw InvalidLineException::vanishingAmount($account, $line->amount, $rate);
            }

            $translated[] = [
                'account_id' => $account->id,
                'currency_code' => $currency->code,
                'amount' => $line->amount,
                'rate' => $rate,
                'functional' => $amount,
                'memo' => $line->memo,
            ];
        }

        return $translated;
    }

    /**
     * The rate to snapshot onto this line.
     *
     * D8: an explicit rate wins. The `exchange_rates` table is the fallback for
     * when no rate was quoted, not the authority — a USD→NGN conversion inside
     * Grey executes at a specific negotiated rate, and that is what belongs on
     * the line.
     *
     * A functional-currency line always stores 1 rather than null, so every
     * line translates the same way and reports need no special case.
     */
    private function resolveRate(
        Account $account,
        Currency $currency,
        Currency $functional,
        ?string $explicit,
        CarbonInterface $date,
    ): string {
        if ($currency->equals($functional)) {
            if ($explicit !== null && ! $this->isUnitRate($explicit)) {
                throw InvalidLineException::redundantRateOnFunctionalLine($account, $explicit);
            }

            return '1';
        }

        if ($explicit !== null) {
            return $explicit;
        }

        $rate = ExchangeRate::resolve($currency, $functional, $date);

        if ($rate === null) {
            throw ExchangeRateNotFoundException::for($currency, $functional, $date);
        }

        return $rate->rate;
    }

    private function isUnitRate(string $rate): bool
    {
        return is_numeric($rate) && (float) $rate === 1.0;
    }

    /**
     * Invariant 1, asserted before the first row is written.
     *
     * Note this sums the *functional* amounts. Summing `amount` would call a
     * mixed-currency entry balanced whenever the raw numbers happened to
     * cancel, which is the single sharpest FX failure mode in the design.
     *
     * @param  list<array{functional: Money, ...}>  $translated
     */
    private function assertBalanced(array $translated): void
    {
        $imbalance = Money::sum(
            array_column($translated, 'functional'),
            Currency::functional()
        );

        if (! $imbalance->isZero()) {
            throw UnbalancedEntryException::by($imbalance);
        }
    }

    /**
     * @param  list<array{functional: Money, ...}>  $translated
     */
    private function totalDebits(array $translated): Money
    {
        $functional = Currency::functional();

        return Money::sum(
            array_filter(
                array_column($translated, 'functional'),
                fn (Money $amount): bool => $amount->isPositive()
            ),
            $functional
        );
    }

    /**
     * Allocate the next human-readable entry number for the fiscal year.
     *
     * A counter row taken under a row lock inside the posting transaction, not
     * MAX(entry_number) + 1: two concurrent postings read the same maximum and
     * the unique index rejects the loser. A PostgreSQL sequence would be
     * race-safe but non-transactional, so a rolled-back posting would burn its
     * number and leave a permanent gap in the numbering.
     */
    private function allocateEntryNumber(int $fiscalYear): string
    {
        DB::table('journal_entry_sequences')->insertOrIgnore([
            'fiscal_year' => $fiscalYear,
            'next_number' => 1,
        ]);

        $next = (int) DB::table('journal_entry_sequences')
            ->where('fiscal_year', $fiscalYear)
            ->lockForUpdate()
            ->value('next_number');

        DB::table('journal_entry_sequences')
            ->where('fiscal_year', $fiscalYear)
            ->update(['next_number' => $next + 1]);

        return sprintf('JE-%d-%04d', $fiscalYear, $next);
    }
}
