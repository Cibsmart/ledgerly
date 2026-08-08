<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\Ledger\PendingLine;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->bookkeeper = User::factory()->bookkeeper()->create();
});

describe('index', function () {
    it('requires authentication', function () {
        $this->get(route('accounts.index'))->assertRedirect(route('login'));
    });

    it('lists the chart in code order with derived postability', function () {
        $response = $this->actingAs($this->bookkeeper)->get(route('accounts.index'));

        $response->assertOk();

        $accounts = collect($response->viewData('page')['props']['accounts']);

        expect($accounts->pluck('code')->all())
            ->toBe($accounts->pluck('code')->sort()->values()->all());

        expect($accounts->firstWhere('code', '1000')['is_postable'])->toBeFalse()
            ->and($accounts->firstWhere('code', '1011')['is_postable'])->toBeTrue();
    });

    it('indents children below their parents', function () {
        $accounts = collect(
            $this->actingAs($this->bookkeeper)
                ->get(route('accounts.index'))
                ->viewData('page')['props']['accounts']
        );

        expect($accounts->firstWhere('code', '1000')['depth'])->toBe(0)
            ->and($accounts->firstWhere('code', '1010')['depth'])->toBe(1)
            ->and($accounts->firstWhere('code', '1011')['depth'])->toBe(2);
    });

    it('hides archived accounts unless asked for them', function () {
        Account::query()->where('code', '6900')->update(['is_active' => false]);

        $visible = collect(
            $this->actingAs($this->bookkeeper)
                ->get(route('accounts.index'))
                ->viewData('page')['props']['accounts']
        );

        expect($visible->firstWhere('code', '6900'))->toBeNull();

        $withArchived = collect(
            $this->actingAs($this->bookkeeper)
                ->get(route('accounts.index', ['archived' => 1]))
                ->viewData('page')['props']['accounts']
        );

        expect($withArchived->firstWhere('code', '6900'))->not->toBeNull();
    });

    it('lets a viewer read the chart but not create', function () {
        $props = $this->actingAs(User::factory()->viewer()->create())
            ->get(route('accounts.index'))
            ->assertOk()
            ->viewData('page')['props'];

        expect($props['can']['create'])->toBeFalse();
    });
});

describe('store', function () {
    it('creates an account', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('accounts.store'), [
                'code' => '6220',
                'name' => 'Training & Development',
                'type' => AccountType::Expense->value,
                'parent_id' => null,
                'currency_code' => null,
                'is_monetary' => false,
                'cash_flow_classification' => 'operating',
                'is_cash_equivalent' => false,
            ])
            ->assertRedirect(route('accounts.index'));

        $account = Account::query()->where('code', '6220')->firstOrFail();

        expect($account->name)->toBe('Training & Development')
            ->and($account->type)->toBe(AccountType::Expense);
    });

    it('rejects a duplicate code', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('accounts.store'), [
                'code' => '6100',
                'name' => 'Another Rent',
                'type' => AccountType::Expense->value,
                'is_monetary' => false,
                'is_cash_equivalent' => false,
            ])
            ->assertSessionHasErrors('code');
    });

    it('rejects an unsupported currency', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('accounts.store'), [
                'code' => '1099',
                'name' => 'Yen Account',
                'type' => AccountType::Asset->value,
                'currency_code' => 'JPY',
                'is_monetary' => true,
                'is_cash_equivalent' => true,
            ])
            ->assertSessionHasErrors('currency_code');
    });

    it('forbids a viewer from creating an account', function () {
        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('accounts.store'), [
                'code' => '6230',
                'name' => 'Nope',
                'type' => AccountType::Expense->value,
                'is_monetary' => false,
                'is_cash_equivalent' => false,
            ])
            ->assertForbidden();

        expect(Account::query()->where('code', '6230')->exists())->toBeFalse();
    });

    it('audits the creation', function () {
        $this->actingAs($this->bookkeeper)->post(route('accounts.store'), [
            'code' => '6240',
            'name' => 'Subscriptions — Audited',
            'type' => AccountType::Expense->value,
            'is_monetary' => false,
            'is_cash_equivalent' => false,
        ]);

        $account = Account::query()->where('code', '6240')->firstOrFail();

        expect(DB::table('activity_log')
            ->where('subject_type', 'account')
            ->where('subject_id', $account->id)
            ->where('event', 'created')
            ->where('causer_id', $this->bookkeeper->id)
            ->exists())->toBeTrue();
    });
});

describe('update', function () {
    it('renames an account', function () {
        $account = Account::query()->where('code', '6110')->firstOrFail();

        $this->actingAs($this->bookkeeper)
            ->patch(route('accounts.update', $account), [
                'code' => '6110',
                'name' => 'Utilities & Power',
                'type' => $account->type->value,
                'parent_id' => null,
                'currency_code' => null,
                'is_monetary' => false,
                'cash_flow_classification' => 'operating',
                'is_cash_equivalent' => false,
            ])
            ->assertRedirect(route('accounts.index'));

        expect($account->fresh()->name)->toBe('Utilities & Power');
    });

    it('refuses to change the type of an account that has lines', function () {
        fiscalYear(2026);

        $cash = Account::query()->where('code', '1011')->firstOrFail();
        $rent = Account::query()->where('code', '6100')->firstOrFail();

        postEntry([
            PendingLine::debit($rent, money(1_000)),
            PendingLine::credit($cash, money(1_000)),
        ]);

        $this->actingAs($this->bookkeeper)
            ->patch(route('accounts.update', $rent), [
                'code' => '6100',
                'name' => 'Rent',
                'type' => AccountType::Asset->value,
                'is_monetary' => false,
                'is_cash_equivalent' => false,
            ])
            ->assertServerError();

        expect($rent->fresh()->type)->toBe(AccountType::Expense);
    });

    it('still allows reclassifying cash flow on an account with lines', function () {
        fiscalYear(2026);

        $cash = Account::query()->where('code', '1011')->firstOrFail();
        $rent = Account::query()->where('code', '6100')->firstOrFail();

        postEntry([
            PendingLine::debit($rent, money(1_000)),
            PendingLine::credit($cash, money(1_000)),
        ]);

        $this->actingAs($this->bookkeeper)
            ->patch(route('accounts.update', $rent), [
                'code' => '6100',
                'name' => 'Rent',
                'type' => AccountType::Expense->value,
                'is_monetary' => false,
                'cash_flow_classification' => 'financing',
                'is_cash_equivalent' => false,
            ])
            ->assertRedirect();

        expect($rent->fresh()->cash_flow_classification->value)->toBe('financing');
    });
});

describe('archive', function () {
    it('archives an account rather than deleting it', function () {
        $account = Account::query()->where('code', '6900')->firstOrFail();

        $this->actingAs($this->bookkeeper)
            ->post(route('accounts.archive', $account))
            ->assertRedirect(route('accounts.index'));

        expect($account->fresh()->is_active)->toBeFalse()
            ->and(Account::query()->where('code', '6900')->exists())->toBeTrue();
    });

    it('refuses to archive a header with active children', function () {
        $header = Account::query()->where('code', '1010')->firstOrFail();

        $this->actingAs($this->bookkeeper)
            ->post(route('accounts.archive', $header))
            ->assertServerError();

        expect($header->fresh()->is_active)->toBeTrue();
    });

    it('restores an archived account', function () {
        $account = Account::query()->where('code', '6900')->firstOrFail();
        $account->forceFill(['is_active' => false])->save();

        $this->actingAs($this->bookkeeper)
            ->post(route('accounts.restore', $account))
            ->assertRedirect(route('accounts.index'));

        expect($account->fresh()->is_active)->toBeTrue();
    });

    it('forbids a viewer from archiving', function () {
        $account = Account::query()->where('code', '6900')->firstOrFail();

        $this->actingAs(User::factory()->viewer()->create())
            ->post(route('accounts.archive', $account))
            ->assertForbidden();

        expect($account->fresh()->is_active)->toBeTrue();
    });

    it('exposes no delete route at all', function () {
        expect(collect(Route::getRoutes())->contains(
            fn ($route): bool => str_starts_with($route->uri(), 'accounts')
                && in_array('DELETE', $route->methods(), true)
        ))->toBeFalse();
    });
});
