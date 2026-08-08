<?php

use App\Enums\AccountType;
use App\Models\Account;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
});

it('populates is_monetary and cash_flow_classification on every account', function () {
    // The plan is explicit that these must be set on *every* account from
    // Phase 1, because retrofitting either means re-classifying the whole chart
    // by hand. A null cash_flow_classification is legitimate only for the cash
    // balances the statement reconciles to, and for headers never posted to.
    $unclassified = Account::query()
        ->whereNull('cash_flow_classification')
        ->where('is_cash_equivalent', false)
        ->whereDoesntHave('children')
        ->pluck('code')
        ->all();

    expect($unclassified)->toBe([]);
});

it('seeds the accounts every later phase depends on by code', function (string $code) {
    expect(Account::query()->where('code', $code)->exists())->toBeTrue();
})->with([
    '3100', // Retained Earnings (D2)
    '3200', // Opening Balance Equity (D2)
    '7100', // FX Gain — fixed by D7/D9
    '8100', // FX Loss — fixed by D7/D9
    '1100', // AR control (D4)
    '2010', // AP control (D4)
    '1300', // WHT Receivable (D5)
    '2110', // WHT Payable (D5)
    '1310', // Input VAT — Recoverable (D5)
    '2100', // Output VAT (D5)
    '2120', // PAYE Payable (R5)
    '2130', // Pension Payable (R5)
    '2020', // Due to Partners (R8)
]);

it('seeds one cash account per financial account in R2', function () {
    $cash = Account::query()
        ->where('is_cash_equivalent', true)
        ->whereDoesntHave('children')
        ->orderBy('code')
        ->pluck('currency_code', 'code')
        ->all();

    expect($cash)->toBe([
        '1011' => 'NGN',  // Zenith
        '1012' => 'USD',  // Zenith
        '1013' => 'NGN',  // Grey
        '1014' => 'USD',  // Grey
        '1015' => 'USD',  // Deel
        '1016' => 'NGN',  // Moniepoint
        '1017' => 'NGN',  // Kuda
    ]);
});

it('leaves code 1018 free rather than inventing an eighth institution', function () {
    expect(Account::query()->where('code', '1018')->exists())->toBeFalse();
});

it('marks cash, receivables and payables monetary and the rest not', function () {
    $monetary = fn (string $code): bool => (bool) Account::query()->where('code', $code)->value('is_monetary');

    expect($monetary('1011'))->toBeTrue()   // cash
        ->and($monetary('1100'))->toBeTrue() // receivable
        ->and($monetary('2020'))->toBeTrue() // payable — R8, revalued at year end
        ->and($monetary('1200'))->toBeFalse() // prepayment: a right to services
        ->and($monetary('1520'))->toBeFalse() // shares
        ->and($monetary('1610'))->toBeFalse() // fixed asset
        ->and($monetary('3100'))->toBeFalse() // equity
        ->and($monetary('4010'))->toBeFalse(); // income statement
});

it('nests accounts under the right parents', function () {
    $zenith = Account::query()->where('code', '1011')->firstOrFail();

    expect($zenith->parent->code)->toBe('1010')
        ->and($zenith->parent->parent->code)->toBe('1000');
});

it('makes header accounts unpostable and leaves their children postable', function () {
    expect(Account::query()->where('code', '1000')->firstOrFail()->isPostable())->toBeFalse()
        ->and(Account::query()->where('code', '1010')->firstOrFail()->isPostable())->toBeFalse()
        ->and(Account::query()->where('code', '4000')->firstOrFail()->isPostable())->toBeFalse()
        ->and(Account::query()->where('code', '1011')->firstOrFail()->isPostable())->toBeTrue()
        ->and(Account::query()->where('code', '4010')->firstOrFail()->isPostable())->toBeTrue();
});

it('derives normal balance from type rather than storing it', function () {
    expect(Schema::hasColumn('accounts', 'normal_balance'))->toBeFalse()
        ->and(Account::query()->where('code', '1011')->firstOrFail()->normalBalance()->value)->toBe('debit')
        ->and(Account::query()->where('code', '2010')->firstOrFail()->normalBalance()->value)->toBe('credit')
        ->and(Account::query()->where('code', '4010')->firstOrFail()->normalBalance()->value)->toBe('credit')
        ->and(Account::query()->where('code', '6100')->firstOrFail()->normalBalance()->value)->toBe('debit');
});

it('classifies contra-asset accounts as assets, not liabilities', function () {
    expect(Account::query()->where('code', '1615')->firstOrFail()->type)->toBe(AccountType::Asset)
        ->and(Account::query()->where('code', '1635')->firstOrFail()->type)->toBe(AccountType::Asset);
});

it('does not seed a current year earnings account', function () {
    // It would be a stored statement figure, which invariant 3 forbids.
    expect(Account::query()->where('name', 'like', '%Current Year Earnings%')->exists())->toBeFalse();
});

it('is idempotent', function () {
    $before = Account::count();

    $this->seed(ChartOfAccountsSeeder::class);

    expect(Account::count())->toBe($before);
});
