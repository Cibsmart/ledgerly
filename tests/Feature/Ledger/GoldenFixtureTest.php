<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Database\Seeders\GoldenFixtureSeeder;
use Tests\Fixtures\GoldenFixtureExpectations;

/**
 * The golden fixture, asserted against figures derived by hand rather than by
 * the code under test. Every later phase asserts against this same fixture.
 */
beforeEach(function () {
    $this->seed(GoldenFixtureSeeder::class);
});

it('posts the expected number of entries across two periods', function () {
    expect(JournalEntry::count())->toBe(GoldenFixtureExpectations::ENTRY_COUNT);

    $byPeriod = JournalEntry::query()
        ->join('periods', 'periods.id', '=', 'journal_entries.period_id')
        ->selectRaw('periods.name, count(*) as total')
        ->groupBy('periods.name')
        ->pluck('total', 'name')
        ->all();

    expect(array_keys($byPeriod))->toEqualCanonicalizing(['January 2026', 'February 2026']);
});

it('nets to zero overall', function () {
    expect((int) DB::table('journal_lines')->sum('functional_amount_minor'))->toBe(0);
});

it('balances every individual entry', function () {
    $unbalanced = DB::table('journal_lines')
        ->selectRaw('journal_entry_id, sum(functional_amount_minor) as imbalance')
        ->groupBy('journal_entry_id')
        ->havingRaw('sum(functional_amount_minor) <> 0')
        ->pluck('imbalance', 'journal_entry_id')
        ->all();

    expect($unbalanced)->toBe([]);
});

it('matches the hand-verified trial balance account by account', function () {
    $actual = DB::table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->selectRaw('accounts.code, sum(journal_lines.functional_amount_minor) as balance')
        ->groupBy('accounts.code')
        ->pluck('balance', 'accounts.code')
        ->map(fn ($balance): int => (int) $balance)
        ->all();

    $expected = GoldenFixtureExpectations::trialBalance();

    // Accounts expected to net to zero still have lines, so they appear in
    // both — the comparison is over the union, and a surprise account on
    // either side is a failure.
    ksort($actual);
    ksort($expected);

    expect($actual)->toBe($expected);
});

it('matches the hand-verified statement totals', function () {
    $totals = GoldenFixtureExpectations::statementTotals();

    // A trial balance nets each account first and then columns the result —
    // it is not the gross sum of every debit line, which double-counts an
    // account that was both debited and credited during the period.
    $balances = DB::table('journal_lines')
        ->selectRaw('account_id, sum(functional_amount_minor) as balance')
        ->groupBy('account_id')
        ->pluck('balance')
        ->map(fn ($balance): int => (int) $balance);

    $debits = $balances->filter(fn (int $b): bool => $b > 0)->sum();
    $credits = -$balances->filter(fn (int $b): bool => $b < 0)->sum();

    expect($debits)->toBe($totals['total_debits'])
        ->and($credits)->toBe($totals['total_credits']);

    $byType = fn (AccountType $type): int => (int) DB::table('journal_lines')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.type', $type->value)
        ->sum('journal_lines.functional_amount_minor');

    // Revenue and liabilities carry credit balances, so they come out negative
    // under debit-positive storage.
    expect(-$byType(AccountType::Revenue))->toBe($totals['revenue'])
        ->and($byType(AccountType::Expense))->toBe($totals['expenses'])
        ->and($byType(AccountType::Asset))->toBe($totals['assets'])
        ->and(-$byType(AccountType::Liability))->toBe($totals['liabilities'])
        ->and(-$byType(AccountType::Equity))->toBe($totals['equity_before_profit']);

    $netProfit = -$byType(AccountType::Revenue) - $byType(AccountType::Expense);
    expect($netProfit)->toBe($totals['net_profit']);

    // The accounting equation, which is the whole claim a balance sheet makes.
    expect($byType(AccountType::Asset))->toBe(
        -$byType(AccountType::Liability) - $byType(AccountType::Equity) + $netProfit
    );
});

it('preserves transaction-currency balances that functional amounts cannot recover', function () {
    foreach (GoldenFixtureExpectations::foreignCurrencyBalances() as $code => $expected) {
        $balance = (int) DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', $code)
            ->where('journal_lines.currency_code', $expected['currency'])
            ->sum('journal_lines.amount_minor');

        expect($balance)->toBe($expected['minor'], "Transaction-currency balance for account {$code}");
    }
});

it('translates the same USD account at different rates across the two months', function () {
    // The property that makes this fixture worth having: 1015's functional
    // balance cannot be reconstructed from its USD balance and any one rate.
    $deel = Account::query()->where('code', '1015')->firstOrFail();

    // Read through the model so the decimal cast normalises the value. A raw
    // query returns '1600' on sqlite and '1600.0000000000' on PostgreSQL, and
    // a test that asserts on the raw form passes on one engine only.
    $rates = JournalLine::query()
        ->where('account_id', $deel->id)
        ->get()
        ->pluck('exchange_rate')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($rates)->toBe(['1600.0000000000', '1650.0000000000']);
});

it('books the FX residual of an own-account conversion rather than hiding it', function () {
    $fxLoss = Account::query()->where('code', '8100')->firstOrFail();

    $conversion = JournalEntry::query()
        ->where('memo', 'like', 'Grey USD to NGN%')
        ->with('lines')
        ->firstOrFail();

    expect($conversion->lines)->toHaveCount(3)
        ->and($conversion->lines->firstWhere('account_id', $fxLoss->id)->functional_amount_minor->minor)
        ->toBe(2_000_000)
        ->and($conversion->lines)->toBalance();
});

it('records the UpSub deduction as gross revenue against the payable, not net cash', function () {
    $entry = JournalEntry::query()
        ->where('memo', 'like', 'UpSub February settlement%')
        ->with('lines.account')
        ->firstOrFail();

    $revenue = $entry->lines->first(fn ($line): bool => $line->account->code === '4010');
    $payable = $entry->lines->first(fn ($line): bool => $line->account->code === '2020');
    $cash = $entry->lines->first(fn ($line): bool => $line->account->code === '1015');

    // Gross revenue of $2,000, not the $1,400 that actually arrived.
    expect($revenue->amount_minor->minor)->toBe(-200_000)
        ->and($cash->amount_minor->minor)->toBe(140_000)
        ->and($payable->amount_minor->minor)->toBe(60_000)
        // The payable is released at the rate it was booked at, not February's.
        ->and($payable->exchange_rate)->toBe('1600.0000000000')
        ->and($entry->lines)->toBalance();
});

it('produces identical figures every time it is run', function () {
    $snapshot = fn (): string => DB::table('journal_lines')
        ->orderBy('id')
        ->get(['account_id', 'currency_code', 'amount_minor', 'exchange_rate', 'functional_amount_minor'])
        ->toJson();

    $first = $snapshot();

    // Clearing through the query builder on purpose: the models refuse to
    // delete a posted entry, which is the behaviour under test elsewhere.
    DB::table('journal_lines')->delete();
    DB::table('journal_entries')->delete();
    DB::table('journal_entry_sequences')->delete();

    $this->seed(GoldenFixtureSeeder::class);

    expect($snapshot())->toBe($first);
});
