<?php

use App\Actions\Ledger\ReverseEntry;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Support\Ledger\PendingLine;

/**
 * Third of the plan's testing priorities: Viewers cannot post, Bookkeepers
 * cannot unlock closed periods.
 */
beforeEach(function () {
    fiscalYear(2026);

    $this->cash = Account::factory()->cash()->create(['code' => '1011']);
    $this->revenue = Account::factory()->revenue()->create(['code' => '4010']);
    $this->january = Period::query()->where('name', 'January 2026')->firstOrFail();
});

it('gives every role read access to the ledger', function (Role $role) {
    $user = User::factory()->role($role)->create();

    expect($user->can('viewAny', Account::class))->toBeTrue()
        ->and($user->hasPermission(Permission::ViewLedger))->toBeTrue();
})->with([Role::Admin, Role::Bookkeeper, Role::Viewer]);

describe('viewers', function () {
    beforeEach(fn () => $this->user = User::factory()->viewer()->create());

    it('cannot post entries', function () {
        expect($this->user->can('create', JournalEntry::class))->toBeFalse()
            ->and($this->user->hasPermission(Permission::PostEntries))->toBeFalse();
    });

    it('cannot reverse entries', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        expect($this->user->can('reverse', $entry))->toBeFalse();
    });

    it('cannot manage the chart of accounts', function () {
        expect($this->user->can('create', Account::class))->toBeFalse()
            ->and($this->user->can('update', $this->cash))->toBeFalse()
            ->and($this->user->can('archive', $this->cash))->toBeFalse();
    });

    it('cannot close or reopen periods', function () {
        expect($this->user->can('close', $this->january))->toBeFalse()
            ->and($this->user->can('reopen', $this->january))->toBeFalse();
    });

    it('cannot maintain exchange rates', function () {
        expect($this->user->can('create', ExchangeRate::class))->toBeFalse();
    });
});

describe('bookkeepers', function () {
    beforeEach(fn () => $this->user = User::factory()->bookkeeper()->create());

    it('can post, reverse and manage accounts', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        expect($this->user->can('create', JournalEntry::class))->toBeTrue()
            ->and($this->user->can('reverse', $entry))->toBeTrue()
            ->and($this->user->can('create', Account::class))->toBeTrue()
            ->and($this->user->can('create', ExchangeRate::class))->toBeTrue();
    });

    it('can close a period', function () {
        expect($this->user->can('close', $this->january))->toBeTrue();
    });

    it('cannot reopen a closed period', function () {
        $this->january->forceFill([
            'closed_at' => now(),
            'closed_by' => User::factory()->admin()->create()->id,
        ])->save();

        expect($this->user->can('reopen', $this->january->fresh()))->toBeFalse()
            ->and($this->user->hasPermission(Permission::ReopenPeriods))->toBeFalse();
    });
});

describe('administrators', function () {
    beforeEach(fn () => $this->user = User::factory()->admin()->create());

    it('can reopen a closed period', function () {
        $this->january->forceFill([
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ])->save();

        expect($this->user->can('reopen', $this->january->fresh()))->toBeTrue();
    });

    it('holds every permission', function () {
        foreach (Permission::cases() as $permission) {
            expect($this->user->hasPermission($permission))->toBeTrue();
        }
    });
});

describe('invariants no role can override', function () {
    it('never allows editing or deleting a posted entry', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create();

            expect($user->can('update', $entry))->toBeFalse()
                ->and($user->can('delete', $entry))->toBeFalse();
        }
    });

    it('never allows deleting an account', function () {
        foreach (Role::cases() as $role) {
            expect(User::factory()->role($role)->create()->can('delete', $this->cash))->toBeFalse();
        }
    });

    it('never allows reversing an entry twice', function () {
        $entry = postEntry([
            PendingLine::debit($this->cash, money(1_000)),
            PendingLine::credit($this->revenue, money(1_000)),
        ]);

        app(ReverseEntry::class)->handle($entry);

        expect(User::factory()->admin()->create()->can('reverse', $entry->fresh()))->toBeFalse();
    });
});
