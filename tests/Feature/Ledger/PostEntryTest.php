<?php

use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
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

/**
 * The write path is the whole product — see the plan's testing priorities.
 * Everything else in the application is a view over what this action wrote.
 */
beforeEach(function () {
    fiscalYear(2026);

    $this->cash = Account::factory()->cash()->create(['code' => '1011', 'name' => 'Zenith NGN']);
    $this->revenue = Account::factory()->revenue()->create(['code' => '4010', 'name' => 'Service Revenue']);
    $this->expense = Account::factory()->expense()->create(['code' => '6100', 'name' => 'Rent']);
});

describe('balance', function () {
    it('posts a balanced entry', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(50_000)),
            PendingLine::credit($this->revenue, money(50_000)),
        ]);

        expect($entry->status)->toBe(JournalEntryStatus::Posted)
            ->and($entry->lines)->toHaveCount(2)
            ->and($entry->lines)->toBalance();
    });

    it('rejects an entry whose debits and credits differ', function () {
        postEntry([
            PendingLine::debit($this->cash, money(50_000)),
            PendingLine::credit($this->revenue, money(49_999)),
        ]);
    })->throws(UnbalancedEntryException::class, 'debits exceed credits');

    it('writes nothing at all when an entry is rejected', function () {
        try {
            postEntry([
                PendingLine::debit($this->cash, money(50_000)),
                PendingLine::credit($this->revenue, money(1)),
            ]);
        } catch (UnbalancedEntryException) {
            // The point of the assertion below.
        }

        expect(JournalEntry::count())->toBe(0)
            ->and(DB::table('journal_lines')->count())->toBe(0);
    });

    it('rejects a single-line entry', function () {
        postEntry([PendingLine::debit($this->cash, money(50_000))]);
    })->throws(UnbalancedEntryException::class, 'at least two lines');

    it('balances an entry split across many lines', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(30_000)),
            PendingLine::debit($this->expense, money(20_000)),
            PendingLine::credit($this->revenue, money(50_000)),
        ]);

        expect($entry->lines)->toHaveCount(3)->and($entry->lines)->toBalance();
    });

    it('nets to zero across any number of balanced entries', function () {
        foreach (range(1, 25) as $i) {
            postEntry([
                PendingLine::debit($this->cash, money($i * 137)),
                PendingLine::credit($this->revenue, money($i * 137)),
            ], entryDate: sprintf('2026-%02d-%02d', random_int(1, 12), random_int(1, 28)));
        }

        $total = (int) DB::table('journal_lines')->sum('functional_amount_minor');

        expect($total)->toBe(0);
    });
});

describe('lines', function () {
    it('stores debits positive and credits negative', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        $debit = $entry->lines->firstWhere('account_id', $this->cash->id);
        $credit = $entry->lines->firstWhere('account_id', $this->revenue->id);

        expect($debit->amount_minor->minor)->toBe(100_000)
            ->and($credit->amount_minor->minor)->toBe(-100_000)
            ->and($debit->isDebit())->toBeTrue()
            ->and($credit->isCredit())->toBeTrue();
    });

    it('exposes debit and credit accessors that the trial balance reads', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        $debit = $entry->lines->firstWhere('account_id', $this->cash->id);

        expect($debit->debit->minor)->toBe(100_000)
            ->and($debit->credit->minor)->toBe(0)
            ->and($debit->functional_debit->minor)->toBe(100_000);
    });

    it('stores a rate of 1 on functional-currency lines rather than null', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        expect($entry->lines->pluck('exchange_rate')->unique()->all())
            ->toBe(['1.0000000000']);
    });

    it('rejects a zero-amount line before it reaches the database', function () {
        PendingLine::debit($this->cash, money(0));
    })->throws(InvalidLineException::class, 'zero amount');

    it('rejects a negative amount passed to debit()', function () {
        PendingLine::debit($this->cash, money(-500));
    })->throws(InvalidLineException::class, 'takes a positive amount');
});

describe('accounts', function () {
    it('refuses to post to a header account', function () {
        $header = Account::factory()->create(['code' => '1000', 'name' => 'Current Assets']);
        Account::factory()->childOf($header)->create(['code' => '1001']);

        postEntry([
            PendingLine::debit($header, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);
    })->throws(InvalidLineException::class, 'has child accounts and cannot be posted to');

    it('refuses to post to an archived account', function () {
        $archived = Account::factory()->archived()->create(['code' => '6999']);

        postEntry([
            PendingLine::debit($archived, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);
    })->throws(InvalidLineException::class, 'is archived');

    it('refuses a currency the account is not denominated in', function () {
        ExchangeRate::factory()->create();

        postEntry([
            PendingLine::debit($this->cash, money(10, 'USD')),
            PendingLine::credit($this->revenue, money(16_000)),
        ]);
    })->throws(InvalidLineException::class, 'cannot hold a [USD] line');

    it('refuses an account that does not exist', function () {
        postEntry([
            PendingLine::debit(9_999_999, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);
    })->throws(InvalidLineException::class, 'does not exist');
});

describe('periods', function () {
    it('assigns the period from the entry date, not from today', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], entryDate: '2026-03-17');

        expect($entry->period->name)->toBe('March 2026')
            ->and($entry->entry_date->toDateString())->toBe('2026-03-17');
    });

    it('refuses to post into a closed period', function () {
        Period::query()->where('name', 'January 2026')->update([
            'closed_at' => now(),
            'closed_by' => User::factory()->admin()->create()->id,
        ]);

        postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], entryDate: '2026-01-15');
    })->throws(ClosedPeriodException::class, 'closed');

    it('refuses to post where no period exists', function () {
        postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], entryDate: '2027-01-15');
    })->throws(PeriodNotFoundException::class, 'No fiscal period covers');
});

describe('entry numbering', function () {
    it('allocates sequential numbers within a fiscal year', function () {
        $numbers = collect(range(1, 3))->map(fn (): string => postEntry([
            PendingLine::debit($this->cash, money(100)),
            PendingLine::credit($this->revenue, money(100)),
        ])->entry_number)->all();

        expect($numbers)->toBe(['JE-2026-0001', 'JE-2026-0002', 'JE-2026-0003']);
    });

    it('does not consume a number when the posting is rejected', function () {
        try {
            postEntry([
                PendingLine::debit($this->cash, money(100)),
                PendingLine::credit($this->revenue, money(99)),
            ]);
        } catch (UnbalancedEntryException) {
            // A rolled-back posting must return its number, or the numbering
            // gains a permanent gap that looks like a deleted entry.
        }

        $entry = postEntry([
            PendingLine::debit($this->cash, money(100)),
            PendingLine::credit($this->revenue, money(100)),
        ]);

        expect($entry->entry_number)->toBe('JE-2026-0001');
    });
});

describe('attribution', function () {
    it('records who posted the entry and when', function () {
        $user = User::factory()->bookkeeper()->create();

        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], user: $user);

        expect($entry->created_by)->toBe($user->id)
            ->and($entry->posted_by)->toBe($user->id)
            ->and($entry->posted_at)->not->toBeNull();
    });

    it('writes an audit entry inside the posting transaction', function () {
        $user = User::factory()->bookkeeper()->create();

        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], user: $user);

        $activity = DB::table('activity_log')
            ->where('subject_type', 'journal_entry')
            ->where('subject_id', $entry->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->event)->toBe('posted')
            ->and($activity->causer_id)->toBe($user->id);
    });

    it('tags provenance so a subsidiary module can be traced back', function () {
        $source = Period::query()->where('name', 'January 2026')->firstOrFail();

        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], source: $source);

        expect($entry->source_type)->toBe('period')
            ->and($entry->source_id)->toBe($source->id);
    });
});

describe('immutability', function () {
    it('refuses to update a posted entry', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        $entry->memo = 'tampered';
        $entry->save();
    })->throws(LogicException::class, 'is posted and cannot be modified');

    it('refuses to delete a posted entry', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        $entry->delete();
    })->throws(LogicException::class, 'is posted and cannot be deleted');

    it('refuses to update a journal line', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        $line = $entry->lines->first();
        $line->memo = 'tampered';
        $line->save();
    })->throws(LogicException::class, 'journal lines are immutable');

    it('refuses to delete a journal line', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        $entry->lines->first()->delete();
    })->throws(LogicException::class, 'journal lines are immutable');
});

describe('foreign currency', function () {
    beforeEach(function () {
        $this->usd = Account::factory()->cash('USD')->create(['code' => '1015', 'name' => 'Deel USD']);
    });

    it('translates a foreign line using the exchange_rates table', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600')->effectiveOn('2026-01-01')->create();

        $entry = postEntry([
            PendingLine::debit($this->usd, money(100, 'USD')),
            PendingLine::credit($this->revenue, money(160_000)),
        ]);

        $usdLine = $entry->lines->firstWhere('account_id', $this->usd->id);

        expect($usdLine->amount_minor->minor)->toBe(10_000)
            ->and($usdLine->amount_minor->currency->code)->toBe('USD')
            ->and($usdLine->functional_amount_minor->minor)->toBe(16_000_000)
            ->and($entry->lines)->toBalance();
    });

    it('prefers an explicit per-line rate over the table (D8)', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600')->effectiveOn('2026-01-01')->create();

        $entry = postEntry([
            PendingLine::debit($this->usd, money(100, 'USD'), exchangeRate: '1750'),
            PendingLine::credit($this->revenue, money(175_000)),
        ]);

        $usdLine = $entry->lines->firstWhere('account_id', $this->usd->id);

        expect($usdLine->exchange_rate)->toBe('1750.0000000000')
            ->and($usdLine->functional_amount_minor->minor)->toBe(17_500_000);
    });

    it('uses the latest rate effective on or before the entry date', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600')->effectiveOn('2026-01-01')->create();
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1700')->effectiveOn('2026-02-01')->create();

        $entry = postEntry([
            PendingLine::debit($this->usd, money(100, 'USD')),
            PendingLine::credit($this->revenue, money(170_000)),
        ], entryDate: '2026-03-10');

        expect($entry->lines->firstWhere('account_id', $this->usd->id)->exchange_rate)
            ->toBe('1700.0000000000');
    });

    it('refuses a foreign line with no rate available', function () {
        postEntry([
            PendingLine::debit($this->usd, money(100, 'USD')),
            PendingLine::credit($this->revenue, money(160_000)),
        ]);
    })->throws(ExchangeRateNotFoundException::class, 'No USD/NGN exchange rate');

    it('does not re-derive a posted line when the rate table is corrected', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600')->effectiveOn('2026-01-01')->create();

        $entry = postEntry([
            PendingLine::debit($this->usd, money(100, 'USD')),
            PendingLine::credit($this->revenue, money(160_000)),
        ]);

        ExchangeRate::query()->update(['rate' => '9999']);

        $entry->refresh();
        $usdLine = $entry->lines->firstWhere('account_id', $this->usd->id);

        expect($usdLine->exchange_rate)->toBe('1600.0000000000')
            ->and($usdLine->functional_amount_minor->minor)->toBe(16_000_000);
    });

    it('rejects a mixed-currency entry that only balances in transaction terms', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1600')->effectiveOn('2026-01-01')->create();

        // 100 USD debit against 100 NGN credit: the raw numbers cancel, the
        // functional amounts are nowhere near each other. This is the sharpest
        // FX failure mode in the design.
        postEntry([
            PendingLine::debit($this->usd, money(100, 'USD')),
            PendingLine::credit($this->revenue, money(100)),
        ]);
    })->throws(UnbalancedEntryException::class);

    it('rejects a rate other than 1 on a functional-currency line', function () {
        postEntry([
            PendingLine::debit($this->cash, money(1_000), exchangeRate: '1600'),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);
    })->throws(InvalidLineException::class, 'already in the functional currency');

    it('rejects a rate that rounds the line away to nothing', function () {
        $entry = fn () => postEntry([
            PendingLine::debit($this->usd, Money::fromMinor(1, Currency::of('USD')), exchangeRate: '0.0001'),
            PendingLine::credit($this->revenue, money(1)),
        ]);

        expect($entry)->toThrow(InvalidLineException::class, 'rounds to zero');
    });
});
