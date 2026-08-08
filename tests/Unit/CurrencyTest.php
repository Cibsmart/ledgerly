<?php

use App\Support\Currency;

it('resolves a configured currency', function () {
    $ngn = Currency::of('NGN');

    expect($ngn->code)->toBe('NGN')
        ->and($ngn->symbol)->toBe('₦')
        ->and($ngn->scale)->toBe(2)
        ->and($ngn->factor())->toBe(100);
});

it('normalises the code it is given', function () {
    expect(Currency::of(' ngn ')->code)->toBe('NGN');
});

it('rejects an unconfigured currency rather than inventing one', function () {
    Currency::of('XYZ');
})->throws(InvalidArgumentException::class, 'Unsupported currency [XYZ]');

it('resolves the functional currency from config', function () {
    expect(Currency::functional()->code)->toBe('NGN')
        ->and(Currency::of('NGN')->isFunctional())->toBeTrue()
        ->and(Currency::of('USD')->isFunctional())->toBeFalse();
});

it('follows a reconfigured functional currency', function () {
    config()->set('money.functional', 'USD');

    expect(Currency::functional()->code)->toBe('USD')
        ->and(Currency::of('USD')->isFunctional())->toBeTrue();
});

it('lists every configured currency', function () {
    expect(array_keys(Currency::all()))->toBe(['NGN', 'USD', 'EUR', 'GBP']);
});

it('compares by code', function () {
    expect(Currency::of('NGN')->equals(Currency::of('NGN')))->toBeTrue()
        ->and(Currency::of('NGN')->equals(Currency::of('USD')))->toBeFalse();
});

it('serializes for the frontend', function () {
    expect(Currency::of('USD')->jsonSerialize())
        ->toBe(['code' => 'USD', 'symbol' => '$', 'scale' => 2, 'name' => 'US Dollar']);
});

it('stringifies to its code', function () {
    expect((string) Currency::of('GBP'))->toBe('GBP');
});
