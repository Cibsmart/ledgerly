<?php

use App\Actions\Ledger\ReverseEntry;
use App\Models\Account;
use App\Models\Period;
use App\Support\Ledger\PendingLine;
use Illuminate\Database\QueryException;

/**
 * The database-level guarantees, which exist on PostgreSQL only.
 *
 * These are deliberately *not* tested through PostEntry — the action already
 * refuses all of this, so routing through it would prove nothing about the
 * database. Each test writes rows directly, the way a careless seeder, a
 * console script or a tinker session would, and asserts the database refuses
 * them anyway.
 *
 * Pinned to PostgreSQL because sqlite has no deferred constraints, and because
 * Blueprint offers no way to add a CHECK to an existing table on any engine.
 * The plan's risk section is explicit that nothing depending on these is
 * "passing" until it passes here, which is what the tests-pgsql CI job is for.
 */
beforeEach(function () {
    fiscalYear(2026);

    $this->cash = Account::factory()->cash()->create(['code' => '1011']);
    $this->revenue = Account::factory()->revenue()->create(['code' => '4010']);
    $this->period = Period::query()->where('name', 'January 2026')->firstOrFail();

    $this->makeEntry = function (string $number): int {
        return DB::table('journal_entries')->insertGetId([
            'entry_number' => $number,
            'entry_date' => '2026-01-15',
            'period_id' => $this->period->id,
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->makeLine = function (int $entryId, int $accountId, int $amount, ?int $functional = null): void {
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entryId,
            'account_id' => $accountId,
            'currency_code' => 'NGN',
            'amount_minor' => $amount,
            'exchange_rate' => '1.0',
            'functional_amount_minor' => $functional ?? $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'Deferred constraints and CHECK constraints exist on PostgreSQL only.'
);

/*
 * A note on SET CONSTRAINTS, which the next three tests depend on.
 *
 * A deferred constraint is evaluated at COMMIT. RefreshDatabase wraps every
 * test in a transaction it rolls back, so nothing here ever commits and the
 * trigger would never fire — a test written the obvious way passes whether the
 * constraint exists or not, which is the worst possible outcome for the one
 * guarantee that matters most.
 *
 * `SET CONSTRAINTS ALL IMMEDIATE` forces PostgreSQL to evaluate everything
 * still pending, right there, raising exactly the error a COMMIT would.
 */

it('accepts a balanced entry even though it is unbalanced mid-write', function () {
    // The whole point of DEFERRABLE INITIALLY DEFERRED: an entry is
    // legitimately unbalanced between its first and last line.
    $entry = ($this->makeEntry)('RAW-1');
    ($this->makeLine)($entry, $this->cash->id, 500_000);
    ($this->makeLine)($entry, $this->revenue->id, -500_000);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    expect(DB::table('journal_lines')->count())->toBe(2);
});

it('rejects an unbalanced entry written straight to the database', function () {
    expect(function () {
        $entry = ($this->makeEntry)('RAW-2');
        ($this->makeLine)($entry, $this->cash->id, 500_000);
        ($this->makeLine)($entry, $this->revenue->id, -499_999);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    })->toThrow(QueryException::class, 'does not balance');
});

it('rejects a single-line entry written straight to the database', function () {
    expect(function () {
        $entry = ($this->makeEntry)('RAW-3');
        ($this->makeLine)($entry, $this->cash->id, 500_000);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    })->toThrow(QueryException::class, 'does not balance');
});

it('rejects a zero-amount line', function () {
    expect(fn () => DB::transaction(function () {
        $entry = ($this->makeEntry)('RAW-4');
        ($this->makeLine)($entry, $this->cash->id, 0);
        ($this->makeLine)($entry, $this->revenue->id, 0);
    }))->toThrow(QueryException::class, 'journal_lines_amount_nonzero');
});

it('rejects a line whose translation flips the sign', function () {
    // A wrong-signed line still balances if its counterpart is wrong too, so
    // the imbalance check alone would not catch this.
    expect(fn () => DB::transaction(function () {
        $entry = ($this->makeEntry)('RAW-5');
        ($this->makeLine)($entry, $this->cash->id, 500_000, -500_000);
        ($this->makeLine)($entry, $this->revenue->id, -500_000, 500_000);
    }))->toThrow(QueryException::class, 'journal_lines_sign_agrees');
});

it('rejects a non-positive exchange rate', function () {
    expect(fn () => DB::table('journal_lines')->insert([
        'journal_entry_id' => ($this->makeEntry)('RAW-6'),
        'account_id' => $this->cash->id,
        'currency_code' => 'NGN',
        'amount_minor' => 1_000,
        'exchange_rate' => '0',
        'functional_amount_minor' => 1_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'journal_lines_rate_positive');
});

it('rejects a second reversal of the same entry at the unique index', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(1_000)),
        PendingLine::credit($this->revenue, money(1_000)),
    ]);

    app(ReverseEntry::class)->handle($original);

    // Bypassing the action's own check: the database must refuse it too.
    expect(fn () => DB::table('journal_entries')->insert([
        'entry_number' => 'RAW-7',
        'entry_date' => '2026-01-15',
        'period_id' => $this->period->id,
        'status' => 'posted',
        'posted_at' => now(),
        'reverses_journal_entry_id' => $original->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'reverses_journal_entry_id_unique');
});

it('rejects a duplicate entry number', function () {
    ($this->makeEntry)('JE-DUPE');

    expect(fn () => ($this->makeEntry)('JE-DUPE'))
        ->toThrow(QueryException::class, 'entry_number_unique');
});

it('rejects an unknown entry status', function () {
    expect(fn () => DB::table('journal_entries')->insert([
        'entry_number' => 'RAW-8',
        'entry_date' => '2026-01-15',
        'period_id' => $this->period->id,
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'journal_entries_status_known');
});

it('rejects a posted entry with no posted_at', function () {
    expect(fn () => DB::table('journal_entries')->insert([
        'entry_number' => 'RAW-9',
        'entry_date' => '2026-01-15',
        'period_id' => $this->period->id,
        'status' => 'posted',
        'posted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'journal_entries_posted_attribution');
});

it('rejects a period closed with no one recorded as closing it', function () {
    expect(fn () => DB::table('periods')->insert([
        'fiscal_year' => 2027,
        'name' => 'Orphan close',
        'start_date' => '2027-01-01',
        'end_date' => '2027-01-31',
        'closed_at' => now(),
        'closed_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'periods_closed_attribution');
});

it('rejects a period whose dates are the wrong way round', function () {
    expect(fn () => DB::table('periods')->insert([
        'fiscal_year' => 2027,
        'name' => 'Backwards',
        'start_date' => '2027-03-31',
        'end_date' => '2027-03-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'periods_dates_ordered');
});

it('rejects a self-referential exchange rate', function () {
    expect(fn () => DB::table('exchange_rates')->insert([
        'base_code' => 'NGN',
        'quote_code' => 'NGN',
        'rate' => '1',
        'effective_on' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'exchange_rates_distinct_currencies');
});
