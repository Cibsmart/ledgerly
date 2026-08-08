<?php

use App\Actions\Ledger\ClosePeriod;
use App\Actions\Ledger\CreatePeriod;
use App\Actions\Ledger\ReopenPeriod;
use App\Models\Account;
use App\Models\Period;
use App\Models\User;
use App\Support\Ledger\ClosedPeriodException;
use App\Support\Ledger\PendingLine;
use App\Support\Ledger\PeriodNotFoundException;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

describe('creating', function () {
    it('creates a period and audits it', function () {
        $period = app(CreatePeriod::class)->handle(
            fiscalYear: 2026,
            name: 'January 2026',
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            user: $this->admin,
        );

        expect($period->name)->toBe('January 2026')
            ->and($period->isOpen())->toBeTrue();

        expect(DB::table('activity_log')
            ->where('subject_type', 'period')
            ->where('subject_id', $period->id)
            ->where('event', 'created')
            ->exists())->toBeTrue();
    });

    it('refuses a period that overlaps an existing one', function () {
        app(CreatePeriod::class)->handle(2026, 'January 2026', '2026-01-01', '2026-01-31', $this->admin);
        app(CreatePeriod::class)->handle(2026, 'Overlap', '2026-01-15', '2026-02-15', $this->admin);
    })->throws(PeriodNotFoundException::class, 'Periods must not overlap');

    it('refuses a period that ends before it starts', function () {
        app(CreatePeriod::class)->handle(2026, 'Backwards', '2026-03-31', '2026-03-01', $this->admin);
    })->throws(PeriodNotFoundException::class, 'after it ends');

    it('generates twelve contiguous months for a fiscal year', function () {
        $periods = app(CreatePeriod::class)->forFiscalYear(2026, $this->admin);

        expect($periods)->toHaveCount(12)
            ->and($periods[0]->start_date->toDateString())->toBe('2026-01-01')
            ->and($periods[11]->end_date->toDateString())->toBe('2026-12-31');

        // Contiguous: each period starts the day after the previous one ends.
        foreach (range(1, 11) as $i) {
            expect($periods[$i]->start_date->toDateString())
                ->toBe($periods[$i - 1]->end_date->addDay()->toDateString());
        }
    });
});

describe('closing', function () {
    beforeEach(function () {
        fiscalYear(2026);

        $this->cash = Account::factory()->cash()->create(['code' => '1011']);
        $this->revenue = Account::factory()->revenue()->create(['code' => '4010']);
        $this->january = Period::query()->where('name', 'January 2026')->firstOrFail();
    });

    it('records who closed the period and when', function () {
        $closed = app(ClosePeriod::class)->handle($this->january, $this->admin);

        expect($closed->isClosed())->toBeTrue()
            ->and($closed->closed_by)->toBe($this->admin->id)
            ->and($closed->closed_at)->not->toBeNull();
    });

    it('blocks posting into the period once closed', function () {
        app(ClosePeriod::class)->handle($this->january, $this->admin);

        postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], entryDate: '2026-01-20');
    })->throws(ClosedPeriodException::class);

    it('leaves other periods open', function () {
        app(ClosePeriod::class)->handle($this->january, $this->admin);

        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], entryDate: '2026-02-20');

        expect($entry->period->name)->toBe('February 2026');
    });

    it('refuses to close a period twice', function () {
        app(ClosePeriod::class)->handle($this->january, $this->admin);
        app(ClosePeriod::class)->handle($this->january->fresh(), $this->admin);
    })->throws(ClosedPeriodException::class, 'already closed');

    it('preserves entries already posted into the period', function () {
        postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ], entryDate: '2026-01-20');

        app(ClosePeriod::class)->handle($this->january, $this->admin);

        expect($this->january->fresh()->entries()->count())->toBe(1);
    });
});

describe('reopening', function () {
    beforeEach(function () {
        fiscalYear(2026);
        $this->january = Period::query()->where('name', 'January 2026')->firstOrFail();
    });

    it('reopens a closed period and records the reason', function () {
        app(ClosePeriod::class)->handle($this->january, $this->admin);

        $reopened = app(ReopenPeriod::class)->handle(
            $this->january->fresh(),
            reason: 'Auditor found a misposted invoice',
            user: $this->admin,
        );

        expect($reopened->isOpen())->toBeTrue()
            ->and($reopened->closed_at)->toBeNull()
            ->and($reopened->closed_by)->toBeNull();

        $activity = DB::table('activity_log')
            ->where('subject_type', 'period')
            ->where('event', 'reopened')
            ->first();

        expect(json_decode($activity->properties, true)['reason'])
            ->toBe('Auditor found a misposted invoice');
    });

    it('refuses to reopen a period that is not closed', function () {
        app(ReopenPeriod::class)->handle($this->january, 'no reason', $this->admin);
    })->throws(ClosedPeriodException::class, 'is not closed');
});
