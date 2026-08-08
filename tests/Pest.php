<?php

use App\Support\Currency;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
| Unit tests also extend the application TestCase, without RefreshDatabase. Value objects
| such as App\Support\Money read configuration (the currency symbol and scale), which needs
| a booted container but no database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/**
 * Assert that a set of ledger lines is balanced — total debits equal total credits.
 *
 * The plan's testing priorities require every statement test to assert the trial balance
 * still nets to zero. Accepts anything iterable whose items expose `debit` and `credit`
 * attributes, so it works on a journal entry's lines, a raw array of line data, or a
 * report's underlying rows.
 *
 * **Balance is asserted in the functional currency.** A mixed-currency entry cannot net to
 * zero in transaction-currency terms, so this helper reads the translated amounts —
 * `functional_debit`/`functional_credit` where present, falling back to `debit`/`credit`.
 * Any Money it encounters must be in the functional currency; a transaction-currency amount
 * reaching here is the bug this guard exists to catch, not something to sum anyway.
 */
expect()->extend('toBalance', function () {
    $functional = Currency::functional();
    $debits = 0;
    $credits = 0;

    $read = function (mixed $line, string $translated, string $raw): mixed {
        if (is_array($line)) {
            return $line[$translated] ?? $line[$raw] ?? null;
        }

        return $line->{$translated} ?? $line->{$raw} ?? null;
    };

    $minor = function (mixed $amount) use ($functional): int {
        if ($amount instanceof Money) {
            expect($amount->currency->code)->toBe(
                $functional->code,
                "Trial balance must be asserted in the functional currency [{$functional->code}], "
                ."but a line carried [{$amount->currency->code}]. Use the translated amount."
            );

            return $amount->minor;
        }

        return (int) $amount;
    };

    foreach ($this->value as $line) {
        $debits += $minor($read($line, 'functional_debit', 'debit'));
        $credits += $minor($read($line, 'functional_credit', 'credit'));
    }

    expect($debits)->toBe(
        $credits,
        "Trial balance does not net to zero: debits {$debits} vs credits {$credits} minor units."
    );

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Shorthand for building a Money amount from whole major units in tests.
 * Defaults to the functional currency.
 */
function money(int $major, ?string $currency = null): Money
{
    return Money::fromMajor($major, $currency === null ? Currency::functional() : Currency::of($currency));
}
