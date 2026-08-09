<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Currency;
use App\Support\Ledger\ExchangeRateResolver;
use App\Support\Ledger\OpeningBalanceException;
use App\Support\Ledger\PendingLine;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Bring existing balances onto the books as a posted journal entry.
 *
 * This is the first thing that happens after the chart is seeded, and it is
 * deliberately not a special kind of data: an opening balance is a journal
 * entry like any other, posted through PostEntry, immutable once posted, and
 * corrected by reversal. Nothing here writes a balance to a column.
 *
 * The contra side is `3200 Opening Balance Equity`, which is why that is a real
 * account rather than being netted straight into Retained Earnings: while it
 * carries a balance, the opening balances are unfinished, and that non-zero
 * figure is the signal saying so. When every account's opening balance has been
 * entered, 3200 returns to zero on its own.
 *
 * **The residual is computed in functional terms, and it has to be.** Opening a
 * USD account and an NGN account in the same run produces an entry that cannot
 * net to zero in transaction-currency terms and never will (invariant 1). So
 * this action resolves each line's rate itself, translates, sums, and derives
 * the equity line from that total — then hands PostEntry those same rates as
 * explicit per-line overrides. Sharing ExchangeRateResolver with PostEntry is
 * what guarantees the residual computed here is the residual PostEntry sees; if
 * the two resolved rates differently the entry would simply fail to balance,
 * for a reason no error message would be able to explain.
 */
final class RecordOpeningBalances
{
    /**
     * The contra account, by chart code. Looked up rather than configured: the
     * chart already names it, and a config key pointing at an account id would
     * be a second place the answer lives.
     */
    public const EQUITY_ACCOUNT_CODE = '3200';

    /**
     * Marks the entries this action produced, so the screen can list what has
     * already been opened. There is no subsidiary record to hang
     * `source_type`/`source_id` off — an opening balance is not produced by
     * another module, it is where the books start.
     */
    public const REFERENCE = 'OPENING-BALANCE';

    public function __construct(
        private readonly PostEntry $postEntry,
        private readonly RecordAudit $audit,
        private readonly ExchangeRateResolver $rates,
    ) {}

    /**
     * @param  list<PendingLine>  $balances  One line per account, signed —
     *                                       debit for a balance an asset
     *                                       carries, credit for a liability or
     *                                       equity one. Build them with
     *                                       PendingLine::debit()/credit().
     */
    public function handle(
        CarbonInterface|string $asOf,
        array $balances,
        ?string $memo = null,
        ?User $user = null,
    ): JournalEntry {
        $actor = $user ?? Auth::user();
        $date = Date::parse($asOf)->startOfDay();

        if ($balances === []) {
            throw OpeningBalanceException::noBalances();
        }

        return DB::transaction(function () use ($date, $balances, $memo, $actor): JournalEntry {
            $equity = $this->resolveEquityAccount();

            $this->assertOneLinePerAccount($balances, $equity);

            [$lines, $residual] = $this->withEquityLine($balances, $equity, $date);

            $entry = $this->postEntry->handle(
                entryDate: $date,
                lines: $lines,
                memo: $memo ?? 'Opening balances',
                reference: self::REFERENCE,
                user: $actor,
            );

            // PostEntry has already audited the posting itself. What that entry
            // cannot say is that this was an opening balance run and where the
            // equity account now stands — which is the number someone will come
            // back asking about.
            $this->audit->handle(
                event: AuditEvent::Posted,
                subject: $entry,
                properties: [
                    'entry_number' => $entry->entry_number,
                    'as_of' => $date->toDateString(),
                    'account_count' => count($balances),
                    'equity_line_minor' => $residual->minor,
                    'equity_balance_after_minor' => $this->equityBalance($equity)->minor,
                    'functional_currency' => Currency::functional()->code,
                ],
                description: "Recorded opening balances as at {$date->toDateString()}",
                causer: $actor,
                logName: 'ledger',
            );

            return $entry;
        });
    }

    /**
     * The running balance on Opening Balance Equity.
     *
     * Derived by summing posted lines, never read from a column (invariant 3).
     * A non-zero result means the opening balances are not finished.
     */
    public function equityBalance(?Account $equity = null): Money
    {
        $equity ??= $this->resolveEquityAccount();

        /** @var int|string|null $total */
        $total = $equity->lines()->sum('functional_amount_minor');

        return Money::fromMinor((int) $total, Currency::functional());
    }

    public function resolveEquityAccount(): Account
    {
        $equity = Account::query()
            ->withCount('children')
            ->where('code', self::EQUITY_ACCOUNT_CODE)
            ->first();

        if ($equity === null) {
            throw OpeningBalanceException::noEquityAccount(self::EQUITY_ACCOUNT_CODE);
        }

        return $equity;
    }

    /**
     * Translate every balance, then derive the equity line from the total.
     *
     * The rate resolved here is passed back out on each line as an explicit
     * override, so PostEntry snapshots the very rate this sum was taken at.
     *
     * @param  list<PendingLine>  $balances
     * @return array{0: list<PendingLine>, 1: Money}
     */
    private function withEquityLine(array $balances, Account $equity, CarbonInterface $date): array
    {
        $functional = Currency::functional();
        $lines = [];
        $total = Money::zero($functional);

        foreach ($balances as $balance) {
            $rate = $this->rates->resolve(
                from: $balance->amount->currency,
                to: $functional,
                explicit: $balance->exchangeRate,
                date: $date,
            );

            $total = $total->add($balance->amount->convertTo($functional, $rate));

            $lines[] = PendingLine::signed(
                account: $balance->accountId,
                amount: $balance->amount,
                exchangeRate: $rate,
                memo: $balance->memo,
            );
        }

        $residual = $total->negate();

        // A run whose balances already net to zero in functional terms needs no
        // equity line, and PendingLine rightly refuses to build a zero one. It
        // happens: opening a receivable and the payable that offsets it in the
        // same run is a perfectly ordinary thing to do.
        if (! $residual->isZero()) {
            $lines[] = PendingLine::signed(
                account: $equity,
                amount: $residual,
                memo: 'Opening balance equity',
            );
        }

        return [$lines, $residual];
    }

    /**
     * @param  list<PendingLine>  $balances
     */
    private function assertOneLinePerAccount(array $balances, Account $equity): void
    {
        $seen = [];

        foreach ($balances as $balance) {
            if ($balance->accountId === $equity->id) {
                throw OpeningBalanceException::equityAccountGiven($equity);
            }

            if (isset($seen[$balance->accountId])) {
                /** @var Account $account */
                $account = Account::query()->findOrFail($balance->accountId);

                throw OpeningBalanceException::duplicateAccount($account);
            }

            $seen[$balance->accountId] = true;
        }
    }
}
