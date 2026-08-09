<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\RecordOpeningBalances;
use App\Http\Requests\OpeningBalances\OpeningBalanceStoreRequest;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\Currency;
use App\Support\Ledger\LedgerException;
use App\Support\Ledger\PendingLine;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opening balances — where the books start.
 *
 * The screen's real job is the Opening Balance Equity figure it shows: while
 * that account carries a balance, the opening balances are unfinished. It is
 * computed from posted lines every time this page renders, never stored.
 */
class OpeningBalanceController extends Controller
{
    public function create(Request $request, RecordOpeningBalances $openingBalances): Response
    {
        $this->authorize('create', JournalEntry::class);

        $equity = $openingBalances->resolveEquityAccount();

        $accounts = Account::query()
            ->withCount('children')
            ->postable()
            ->whereKeyNot($equity->id)
            ->inChartOrder()
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type_label' => $account->type->label(),
                // Null means the account takes any currency and the form must
                // ask; otherwise the denomination is fixed and the form shows it.
                'currency_code' => $account->currency_code,
                // The side this account's balance normally falls on, used only
                // to preselect the radio. The user still chooses: a contra
                // account carries a balance on the other side, and guessing
                // would silently reverse it.
                'normal_balance' => $account->normalBalance()->value,
            ])
            ->values()
            ->all();

        return Inertia::render('opening-balances/create', [
            'accounts' => $accounts,
            'equity' => [
                'code' => $equity->code,
                'name' => $equity->name,
                'balance' => $openingBalances->equityBalance($equity),
            ],
            'recorded' => $this->recordedRuns(),
            'functionalCurrency' => Currency::functional()->code,
            'currencies' => array_map(
                fn (Currency $currency): array => [
                    'value' => $currency->code,
                    'label' => "{$currency->code} — {$currency->name}",
                ],
                array_values(Currency::all()),
            ),
        ]);
    }

    public function store(
        OpeningBalanceStoreRequest $request,
        RecordOpeningBalances $openingBalances,
    ): RedirectResponse {
        $this->authorize('create', JournalEntry::class);

        $validated = $request->validated();

        /** @var list<array{account_id: int|string, side: string, amount: string, currency_code: string, exchange_rate?: string|null, memo?: string|null}> $lines */
        $lines = $validated['lines'];

        $balances = array_map(
            fn (array $line): PendingLine => $this->toPendingLine($line),
            $lines,
        );

        try {
            $entry = $openingBalances->handle(
                asOf: (string) $validated['as_of'],
                balances: $balances,
                memo: $validated['memo'] ?? null,
                user: $request->user(),
            );
        } catch (LedgerException $exception) {
            // The ledger's refusals are the real rules — closed period, no
            // period, missing rate, header account. Surfacing one as a form
            // error rather than a 500 is presentation, and belongs here rather
            // than in the action, which has no idea it is behind a form.
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Opening balances posted as :number.', ['number' => $entry->entry_number]),
        ]);

        return to_route('opening-balances.create');
    }

    /**
     * @param  array{account_id: int|string, side: string, amount: string, currency_code: string, exchange_rate?: string|null, memo?: string|null}  $line
     */
    private function toPendingLine(array $line): PendingLine
    {
        $amount = Money::parse($line['amount'], Currency::of($line['currency_code']));
        $accountId = (int) $line['account_id'];
        $rate = ($line['exchange_rate'] ?? null) ?: null;
        $memo = ($line['memo'] ?? null) ?: null;

        return $line['side'] === 'credit'
            ? PendingLine::credit($accountId, $amount, $rate, $memo)
            : PendingLine::debit($accountId, $amount, $rate, $memo);
    }

    /**
     * The opening balance entries already posted.
     *
     * Listed rather than blocked. A second run is legitimate — balances for
     * accounts missed the first time, or a correction posted after reversing
     * the original — and the equity figure above is what says whether the job
     * is done.
     *
     * @return list<array<string, mixed>>
     */
    private function recordedRuns(): array
    {
        $runs = JournalEntry::query()
            ->posted()
            ->where('reference', RecordOpeningBalances::REFERENCE)
            ->withCount('lines')
            ->with('reversal:id,reverses_journal_entry_id,entry_number')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (JournalEntry $entry): array => [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date->toDateString(),
                'memo' => $entry->memo,
                'line_count' => $entry->lines_count,
                // No `reversed` status exists — being reversed is exactly
                // whether a reversing entry points back at this one.
                'reversed_by' => $entry->reversal?->entry_number,
            ])
            ->all();

        return array_values($runs);
    }
}
