<?php

use App\Actions\Ledger\ClosePeriod;
use App\Actions\Ledger\ReverseEntry;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Support\Currency;
use App\Support\Ledger\ClosedPeriodException;
use App\Support\Ledger\EntryNotReversibleException;
use App\Support\Ledger\PendingLine;
use App\Support\Money;

beforeEach(function () {
    fiscalYear(2026);

    $this->cash = Account::factory()->cash()->create(['code' => '1011']);
    $this->revenue = Account::factory()->revenue()->create(['code' => '4010']);
    $this->reverse = app(ReverseEntry::class);
});

it('cancels the original exactly', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(50_000)),
        PendingLine::credit($this->revenue, money(50_000)),
    ]);

    $reversal = $this->reverse->handle($original);

    expect($reversal->lines)->toBalance()
        ->and($reversal->reverses_journal_entry_id)->toBe($original->id);

    // The pair together must leave the books exactly as they were.
    $net = (int) DB::table('journal_lines')->sum('functional_amount_minor');
    expect($net)->toBe(0);

    foreach ($original->lines as $line) {
        $mirror = $reversal->lines->firstWhere('account_id', $line->account_id);

        expect($mirror->functional_amount_minor->minor)
            ->toBe(-$line->functional_amount_minor->minor);
    }
});

it('leaves the original untouched', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(50_000)),
        PendingLine::credit($this->revenue, money(50_000)),
    ]);

    $before = $original->replicate()->toArray();

    $this->reverse->handle($original);

    $original->refresh();

    expect($original->status->value)->toBe('posted')
        ->and($original->memo)->toBe($before['memo'])
        ->and($original->lines)->toHaveCount(2);
});

it('derives "reversed" from the reversal existing, not from a status column', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(1_000)),
        PendingLine::credit($this->revenue, money(1_000)),
    ]);

    expect($original->isReversed())->toBeFalse();

    $this->reverse->handle($original);

    expect($original->fresh()->isReversed())->toBeTrue()
        ->and(Schema::hasColumn('journal_entries', 'reversed_at'))->toBeFalse();
});

it('refuses to reverse the same entry twice', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(1_000)),
        PendingLine::credit($this->revenue, money(1_000)),
    ]);

    $this->reverse->handle($original);
    $this->reverse->handle($original->fresh());
})->throws(EntryNotReversibleException::class, 'already reversed');

it('reverses into the original period by default', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(1_000)),
        PendingLine::credit($this->revenue, money(1_000)),
    ], entryDate: '2026-03-10');

    $reversal = $this->reverse->handle($original);

    expect($reversal->entry_date->toDateString())->toBe('2026-03-10')
        ->and($reversal->period->name)->toBe('March 2026');
});

it('refuses to reverse into a closed period without an explicit date', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(1_000)),
        PendingLine::credit($this->revenue, money(1_000)),
    ], entryDate: '2026-03-10');

    app(ClosePeriod::class)->handle(
        Period::query()->where('name', 'March 2026')->firstOrFail(),
        User::factory()->admin()->create(),
    );

    $this->reverse->handle($original->fresh());
})->throws(ClosedPeriodException::class, 'is closed');

it('reverses into a later open period when given one', function () {
    $original = postEntry([
        PendingLine::debit($this->cash, money(1_000)),
        PendingLine::credit($this->revenue, money(1_000)),
    ], entryDate: '2026-03-10');

    app(ClosePeriod::class)->handle(
        Period::query()->where('name', 'March 2026')->firstOrFail(),
        User::factory()->admin()->create(),
    );

    $reversal = $this->reverse->handle($original->fresh(), reversalDate: '2026-04-01');

    expect($reversal->period->name)->toBe('April 2026')
        ->and($reversal->lines)->toBalance();
});

it('refuses to reverse a draft', function () {
    $entry = new JournalEntry;
    $entry->entry_number = 'JE-2026-9999';
    $entry->entry_date = '2026-01-10';
    $entry->period_id = Period::query()->where('name', 'January 2026')->value('id');
    $entry->status = JournalEntryStatus::Draft;
    $entry->save();

    $this->reverse->handle($entry);
})->throws(EntryNotReversibleException::class, 'is a draft');

it('records the reversal against the original in the audit trail', function () {
    $user = User::factory()->bookkeeper()->create();

    $original = postEntry([
        PendingLine::debit($this->cash, money(1_000)),
        PendingLine::credit($this->revenue, money(1_000)),
    ], user: $user);

    $reversal = $this->reverse->handle($original, memo: 'Wrong account', user: $user);

    $activity = DB::table('activity_log')
        ->where('subject_type', 'journal_entry')
        ->where('subject_id', $original->id)
        ->where('event', 'reversed')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id)
        ->and(json_decode($activity->properties, true)['reversed_by_entry_number'])
        ->toBe($reversal->entry_number);
});

describe('foreign currency', function () {
    it('reuses the original snapshotted rate rather than resolving a new one', function () {
        $usd = Account::factory()->cash('USD')->create(['code' => '1015']);
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600')->effectiveOn('2026-01-01')->create();

        $original = postEntry([
            PendingLine::debit($usd, money(100, 'USD')),
            PendingLine::credit($this->revenue, money(160_000)),
        ], entryDate: '2026-01-10');

        // The rate moves sharply before the correction is spotted. Re-resolving
        // it would leave a residual that looks exactly like an FX movement and
        // is in fact a broken reversal.
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1900')->effectiveOn('2026-02-01')->create();

        $reversal = $this->reverse->handle($original, reversalDate: '2026-02-15');

        $usdLine = $reversal->lines->firstWhere('account_id', $usd->id);

        expect($usdLine->exchange_rate)->toBe('1600.0000000000')
            ->and($usdLine->functional_amount_minor->minor)->toBe(-16_000_000)
            ->and((int) DB::table('journal_lines')->sum('functional_amount_minor'))->toBe(0);
    });

    it('cancels an odd-rate entry to the minor unit', function () {
        $usd = Account::factory()->cash('USD')->create(['code' => '1015']);

        // $37.00 at 1633.3333333333 translates to ₦60,433.33 — a rate that does
        // not divide cleanly is exactly where a reversal built by re-converting
        // would drift by a kobo.
        $translated = Money::fromMinor(6_043_333, Currency::functional());

        $original = postEntry([
            PendingLine::debit($usd, money(37, 'USD'), exchangeRate: '1633.3333333333'),
            PendingLine::credit($this->revenue, $translated),
        ]);

        $this->reverse->handle($original);

        expect((int) DB::table('journal_lines')->sum('functional_amount_minor'))->toBe(0);
    });
});
