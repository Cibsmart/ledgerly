<?php

use App\Actions\Ledger\ClosePeriod;
use App\Actions\Ledger\RecordOpeningBalances;
use App\Actions\Ledger\ReverseEntry;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Models\User;
use App\Support\Currency;
use App\Support\Ledger\ClosedPeriodException;
use App\Support\Ledger\ExchangeRateNotFoundException;
use App\Support\Ledger\OpeningBalanceException;
use App\Support\Ledger\PendingLine;
use App\Support\Money;
use Spatie\Activitylog\Models\Activity;

/**
 * Opening balances are where the books start, and they are deliberately not a
 * special kind of data: an ordinary posted entry against 3200, immutable, and
 * corrected only by reversal.
 */
beforeEach(function () {
    fiscalYear(2026);

    $this->equity = Account::factory()->equity()->create([
        'code' => RecordOpeningBalances::EQUITY_ACCOUNT_CODE,
        'name' => 'Opening Balance Equity',
    ]);

    $this->ngnCash = Account::factory()->cash()->create(['code' => '1011', 'name' => 'Zenith NGN']);
    $this->usdCash = Account::factory()->cash('USD')->create(['code' => '1015', 'name' => 'Deel USD']);
    $this->payable = Account::factory()->liability()->create(['code' => '2010', 'name' => 'Accounts Payable']);

    $this->action = app(RecordOpeningBalances::class);
});

describe('posting', function () {
    it('posts one entry with the equity line derived from the balances', function () {
        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ]);

        expect($entry->lines)->toHaveCount(2)->toBalance()
            ->and($entry->reference)->toBe(RecordOpeningBalances::REFERENCE);

        $equityLine = $entry->lines->firstWhere('account_id', $this->equity->id);

        // Dr Cash / Cr Opening Balance Equity.
        expect($equityLine->functional_amount_minor->minor)->toBe(-500_000_000);
    });

    it('debits equity when the balances are net credits', function () {
        $entry = $this->action->handle('2026-01-01', [
            PendingLine::credit($this->payable, money(2_000_000)),
        ]);

        $equityLine = $entry->lines->firstWhere('account_id', $this->equity->id);

        expect($equityLine->functional_amount_minor->minor)->toBe(200_000_000)
            ->and($entry->lines)->toBalance();
    });

    it('posts many accounts as a single entry', function () {
        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
            PendingLine::credit($this->payable, money(2_000_000)),
        ]);

        expect($entry->lines)->toHaveCount(3)->toBalance()
            ->and(JournalEntry::count())->toBe(1);
    });

    /**
     * A run whose balances already offset each other needs no equity line, and
     * PendingLine rightly refuses to build a zero one.
     */
    it('omits the equity line when the balances already net to zero', function () {
        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(2_000_000)),
            PendingLine::credit($this->payable, money(2_000_000)),
        ]);

        expect($entry->lines)->toHaveCount(2)->toBalance()
            ->and($entry->lines->firstWhere('account_id', $this->equity->id))->toBeNull();
    });

    it('records who posted it and when', function () {
        $bookkeeper = User::factory()->bookkeeper()->create();

        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ], user: $bookkeeper);

        expect($entry->created_by)->toBe($bookkeeper->id)
            ->and($entry->posted_by)->toBe($bookkeeper->id)
            ->and($entry->posted_at)->not->toBeNull();
    });
});

describe('foreign currency', function () {
    /**
     * The sharpest case in this action. A USD balance and an NGN balance in one
     * run produce an entry that cannot net to zero in transaction-currency
     * terms — only the translated amounts balance (invariant 1).
     */
    it('derives the equity line from translated amounts, not raw ones', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600.0000000000')
            ->effectiveOn('2026-01-01')->create();

        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
            PendingLine::debit($this->usdCash, money(1_000, 'USD')),
        ]);

        $equityLine = $entry->lines->firstWhere('account_id', $this->equity->id);

        // ₦5,000,000 + ($1,000 × 1600) = ₦6,600,000 of debits.
        expect($equityLine->functional_amount_minor->minor)->toBe(-660_000_000)
            ->and($entry->lines)->toBalance();
    });

    it('snapshots an explicit rate in preference to the table (D8)', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600.0000000000')
            ->effectiveOn('2026-01-01')->create();

        $entry = $this->action->handle('2026-01-01', [
            // The rate this balance was actually struck at.
            PendingLine::debit($this->usdCash, money(1_000, 'USD'), '1725.5'),
        ]);

        $usdLine = $entry->lines->firstWhere('account_id', $this->usdCash->id);
        $equityLine = $entry->lines->firstWhere('account_id', $this->equity->id);

        expect((float) $usdLine->exchange_rate)->toBe(1725.5)
            ->and($usdLine->functional_amount_minor->minor)->toBe(172_550_000)
            // The residual has to agree with the rate that was snapshotted, not
            // with the one in the table, or the entry would not balance at all.
            ->and($equityLine->functional_amount_minor->minor)->toBe(-172_550_000)
            ->and($entry->lines)->toBalance();
    });

    it('refuses a foreign balance with no rate anywhere', function () {
        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->usdCash, money(1_000, 'USD')),
        ]);
    })->throws(ExchangeRateNotFoundException::class);

    it('stores a rate of 1 on functional lines rather than null', function () {
        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ]);

        expect($entry->lines->pluck('exchange_rate')->unique()->all())
            ->toBe(['1.0000000000']);
    });
});

describe('rejections', function () {
    it('refuses the equity account as an entered balance', function () {
        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->equity, money(1_000_000)),
            PendingLine::credit($this->ngnCash, money(1_000_000)),
        ]);
    })->throws(OpeningBalanceException::class, 'computed, not entered');

    it('refuses the same account twice', function () {
        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(1_000_000)),
            PendingLine::debit($this->ngnCash, money(2_000_000)),
        ]);
    })->throws(OpeningBalanceException::class, 'appears more than once');

    it('refuses an empty run', function () {
        $this->action->handle('2026-01-01', []);
    })->throws(OpeningBalanceException::class, 'at least one account balance');

    it('refuses when the chart has no Opening Balance Equity account', function () {
        $this->equity->delete();

        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(1_000_000)),
        ]);
    })->throws(OpeningBalanceException::class, 'Opening Balance Equity');

    it('refuses to post into a closed period', function () {
        // Closed by a real user: PostgreSQL's periods_closed_attribution CHECK
        // refuses a close with no one attached to it (invariant 5).
        app(ClosePeriod::class)->handle(
            Period::query()->containing(now()->setDate(2026, 1, 15))->sole(),
            User::factory()->bookkeeper()->create(),
        );

        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(1_000_000)),
        ]);
    })->throws(ClosedPeriodException::class);

    it('writes nothing when a run is rejected', function () {
        try {
            $this->action->handle('2026-01-01', [
                PendingLine::debit($this->ngnCash, money(1_000_000)),
                PendingLine::debit($this->ngnCash, money(2_000_000)),
            ]);
        } catch (OpeningBalanceException) {
            // The point of the assertion below.
        }

        expect(JournalEntry::count())->toBe(0)
            ->and(DB::table('journal_lines')->count())->toBe(0);
    });
});

describe('the equity balance signal', function () {
    it('is zero before anything is recorded', function () {
        expect($this->action->equityBalance()->isZero())->toBeTrue();
    });

    it('carries the unfinished balance while opening balances are incomplete', function () {
        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ]);

        expect($this->action->equityBalance()->minor)->toBe(-500_000_000);
    });

    /**
     * The signal the screen exists for: once the offsetting side is entered,
     * 3200 returns to zero on its own. No column tracks this — it is summed
     * from posted lines (invariant 3).
     */
    it('returns to zero once the offsetting balances are entered', function () {
        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ]);

        $this->action->handle('2026-01-15', [
            PendingLine::credit($this->payable, money(5_000_000)),
        ]);

        expect($this->action->equityBalance()->isZero())->toBeTrue()
            ->and($this->action->equityBalance()->currency->code)->toBe(Currency::functional()->code);
    });
});

describe('immutability', function () {
    it('cannot be edited, only reversed', function () {
        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ]);

        $entry->memo = 'changed';

        expect(fn () => $entry->save())->toThrow(LogicException::class);
    });

    it('nets the equity account back to zero when reversed', function () {
        $entry = $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ]);

        app(ReverseEntry::class)->handle($entry, '2026-01-15', 'Wrong figure');

        expect($this->action->equityBalance()->isZero())->toBeTrue();
    });
});

describe('audit', function () {
    it('records the run alongside PostEntry\'s own posting entry', function () {
        $bookkeeper = User::factory()->bookkeeper()->create();

        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ], user: $bookkeeper);

        $activity = Activity::query()
            ->where('description', 'like', 'Recorded opening balances%')
            ->sole();

        expect($activity->properties['account_count'])->toBe(1)
            ->and($activity->properties['as_of'])->toBe('2026-01-01')
            ->and($activity->properties['equity_line_minor'])->toBe(-500_000_000)
            ->and($activity->properties['equity_balance_after_minor'])->toBe(-500_000_000)
            ->and($activity->causer_id)->toBe($bookkeeper->id);
    });
});

describe('the screen', function () {
    beforeEach(function () {
        $this->bookkeeper = User::factory()->bookkeeper()->create();
    });

    it('requires authentication', function () {
        $this->get(route('opening-balances.create'))->assertRedirect(route('login'));
    });

    it('shows the equity balance and offers only postable accounts', function () {
        $this->action->handle('2026-01-01', [
            PendingLine::debit($this->ngnCash, money(5_000_000)),
        ]);

        $props = $this->actingAs($this->bookkeeper)
            ->get(route('opening-balances.create'))
            ->assertOk()
            ->viewData('page')['props'];

        expect($props['equity']['balance']->minor)->toBe(-500_000_000)
            ->and($props['recorded'])->toHaveCount(1)
            // The equity account is the computed side, so it is never offered.
            ->and(collect($props['accounts'])->pluck('code')->all())
            ->not->toContain(RecordOpeningBalances::EQUITY_ACCOUNT_CODE);
    });

    it('posts a run submitted from the form', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('opening-balances.store'), [
                'as_of' => '2026-01-01',
                'memo' => 'Balances at incorporation',
                'lines' => [
                    [
                        'account_id' => $this->ngnCash->id,
                        'side' => 'debit',
                        'amount' => '5,000,000.00',
                        'currency_code' => 'NGN',
                    ],
                    [
                        'account_id' => $this->payable->id,
                        'side' => 'credit',
                        'amount' => '2000000',
                        'currency_code' => 'NGN',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('opening-balances.create'));

        $entry = JournalEntry::sole();

        expect($entry->memo)->toBe('Balances at incorporation')
            ->and($entry->lines)->toHaveCount(3)->toBalance()
            ->and($this->action->equityBalance()->minor)->toBe(-300_000_000);
    });

    it('surfaces a ledger refusal as a form error rather than a 500', function () {
        // No period covers 2025, so PostEntry refuses.
        $this->actingAs($this->bookkeeper)
            ->post(route('opening-balances.store'), [
                'as_of' => '2025-06-01',
                'lines' => [[
                    'account_id' => $this->ngnCash->id,
                    'side' => 'debit',
                    'amount' => '1000',
                    'currency_code' => 'NGN',
                ]],
            ])
            ->assertSessionHasErrors('lines');

        expect(JournalEntry::count())->toBe(0);
    });

    it('rejects an amount the currency cannot express', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('opening-balances.store'), [
                'as_of' => '2026-01-01',
                'lines' => [[
                    'account_id' => $this->ngnCash->id,
                    'side' => 'debit',
                    // NGN subdivides into two places, not three.
                    'amount' => '1000.555',
                    'currency_code' => 'NGN',
                ]],
            ])
            ->assertSessionHasErrors('lines.0.amount');

        expect(JournalEntry::count())->toBe(0);
    });

    it('rejects a zero balance', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('opening-balances.store'), [
                'as_of' => '2026-01-01',
                'lines' => [[
                    'account_id' => $this->ngnCash->id,
                    'side' => 'debit',
                    'amount' => '0.00',
                    'currency_code' => 'NGN',
                ]],
            ])
            ->assertSessionHasErrors('lines.0.amount');
    });

    it('refuses a viewer', function () {
        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('opening-balances.store'), [
                'as_of' => '2026-01-01',
                'lines' => [[
                    'account_id' => $this->ngnCash->id,
                    'side' => 'debit',
                    'amount' => '1000',
                    'currency_code' => 'NGN',
                ]],
            ])
            ->assertForbidden();

        expect(JournalEntry::count())->toBe(0);
    });

    it('refuses a currency the account is not denominated in', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('opening-balances.store'), [
                'as_of' => '2026-01-01',
                'lines' => [[
                    'account_id' => $this->ngnCash->id,
                    'side' => 'debit',
                    'amount' => '1000',
                    'currency_code' => 'USD',
                    'exchange_rate' => '1600',
                ]],
            ])
            ->assertSessionHasErrors('lines');

        expect(JournalEntry::count())->toBe(0);
    });
});

it('never lets a run produce an unbalanced ledger', function () {
    ExchangeRate::factory()->pair('USD', 'NGN')->at('1612.3300000000')
        ->effectiveOn('2026-01-01')->create();

    $this->action->handle('2026-01-01', [
        // An amount whose translation does not divide evenly, which is where a
        // residual computed any other way would drift by a kobo.
        PendingLine::debit($this->usdCash, money(1_337, 'USD')),
        PendingLine::debit($this->ngnCash, money(4_444_444)),
        PendingLine::credit($this->payable, money(1_111_111)),
    ]);

    $total = Money::sum(
        JournalLine::all()->map->functional_amount_minor,
        Currency::functional()
    );

    expect($total->isZero())->toBeTrue();
});
