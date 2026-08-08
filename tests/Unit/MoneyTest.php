<?php

use App\Support\Currency;
use App\Support\Money;

function ngn(): Currency
{
    return Currency::of('NGN');
}

function usd(): Currency
{
    return Currency::of('USD');
}

it('constructs from minor and major units', function () {
    expect(Money::fromMinor(12345, ngn())->minor)->toBe(12345)
        ->and(Money::fromMajor(5, ngn())->minor)->toBe(500)
        ->and(Money::zero(ngn())->minor)->toBe(0)
        ->and(Money::fromMinor(1, usd())->currency->code)->toBe('USD');
});

it('parses user-entered amounts', function (string $input, int $expected) {
    expect(Money::parse($input, ngn())->minor)->toBe($expected);
})->with([
    ['5000', 500000],
    ['5,000', 500000],
    ['5,000.00', 500000],
    ['5000.50', 500050],
    ['5000.5', 500050],
    ['₦5,000.00', 500000],
    [' ₦1,234.56 ', 123456],
    ['-150.25', -15025],
    ['0', 0],
]);

it('rejects amounts it cannot parse exactly', function (string $input) {
    Money::parse($input, ngn());
})->with([
    'letters' => ['abc'],
    'empty' => [''],
    'too many decimals' => ['10.005'],
    'trailing dot' => ['10.'],
    'bare decimal' => ['.50'],
])->throws(InvalidArgumentException::class);

it('adds, subtracts and multiplies within one currency', function () {
    $a = Money::fromMinor(1010, ngn());
    $b = Money::fromMinor(495, ngn());

    expect($a->add($b)->minor)->toBe(1505)
        ->and($a->subtract($b)->minor)->toBe(515)
        ->and($b->multiply(3)->minor)->toBe(1485)
        ->and($a->negate()->minor)->toBe(-1010)
        ->and($a->negate()->absolute()->minor)->toBe(1010)
        ->and($a->add($b)->currency->code)->toBe('NGN');
});

it('refuses to add different currencies', function () {
    Money::fromMinor(100, ngn())->add(Money::fromMinor(100, usd()));
})->throws(InvalidArgumentException::class, 'Cannot add [USD] and [NGN]');

it('refuses to subtract different currencies', function () {
    Money::fromMinor(100, ngn())->subtract(Money::fromMinor(100, usd()));
})->throws(InvalidArgumentException::class);

it('refuses to compare different currencies', function () {
    Money::fromMinor(100, ngn())->greaterThan(Money::fromMinor(100, usd()));
})->throws(InvalidArgumentException::class);

it('treats equal magnitudes in different currencies as unequal', function () {
    expect(Money::fromMinor(100, ngn())->equals(Money::fromMinor(100, usd())))->toBeFalse()
        ->and(Money::fromMinor(100, ngn())->equals(Money::fromMinor(100, ngn())))->toBeTrue();
});

it('is immutable under arithmetic', function () {
    $original = Money::fromMinor(1000, ngn());
    $original->add(Money::fromMinor(500, ngn()));

    expect($original->minor)->toBe(1000);
});

it('compares amounts', function () {
    $small = Money::fromMinor(100, ngn());
    $large = Money::fromMinor(200, ngn());

    expect($small->lessThan($large))->toBeTrue()
        ->and($large->greaterThan($small))->toBeTrue()
        ->and(Money::zero(ngn())->isZero())->toBeTrue()
        ->and(Money::fromMinor(-1, ngn())->isNegative())->toBeTrue()
        ->and(Money::fromMinor(1, ngn())->isPositive())->toBeTrue();
});

it('sums amounts of one currency', function () {
    expect(Money::sum([
        Money::fromMinor(100, ngn()),
        Money::fromMinor(250, ngn()),
        Money::fromMinor(-50, ngn()),
    ])->minor)->toBe(300)
        ->and(Money::sum([], ngn())->minor)->toBe(0);
});

it('refuses to sum mixed currencies', function () {
    Money::sum([Money::fromMinor(100, ngn()), Money::fromMinor(100, usd())]);
})->throws(InvalidArgumentException::class, 'Cannot sum mixed currencies');

it('refuses to sum an empty set with no currency to infer', function () {
    Money::sum([]);
})->throws(InvalidArgumentException::class);

it('converts at an explicit rate', function () {
    // 1 USD buys 1,650.25 NGN, so $100.00 is ₦165,025.00
    $converted = Money::fromMajor(100, usd())->convertTo(ngn(), '1650.25');

    expect($converted->minor)->toBe(16502500)
        ->and($converted->currency->code)->toBe('NGN');
});

it('rounds conversions half away from zero', function (int $minor, string $rate, int $expected) {
    expect(Money::fromMinor($minor, usd())->convertTo(ngn(), $rate)->minor)->toBe($expected);
})->with([
    'rounds up at half' => [1, '1.005', 1],
    'rounds down below half' => [1, '1.004', 1],
    'negative rounds away from zero' => [-3, '1.5', -5],
    'positive rounds away from zero' => [3, '1.5', 5],
]);

it('rejects a malformed exchange rate', function (string $rate) {
    Money::fromMinor(100, usd())->convertTo(ngn(), $rate);
})->with([
    'letters' => ['abc'],
    'empty' => [''],
    'two dots' => ['1.2.3'],
])->throws(InvalidArgumentException::class);

it('allocates without losing or inventing minor units', function () {
    $parts = Money::fromMinor(100, ngn())->allocate([1, 1, 1]);

    expect(array_map(fn (Money $part): int => $part->minor, $parts))->toBe([34, 33, 33])
        ->and(Money::sum($parts)->minor)->toBe(100)
        ->and($parts[0]->currency->code)->toBe('NGN');
});

it('allocates by weighted ratios', function () {
    $parts = Money::fromMinor(1000, ngn())->allocate([70, 30]);

    expect(array_map(fn (Money $part): int => $part->minor, $parts))->toBe([700, 300]);
});

it('allocates negative amounts back to the original total', function () {
    expect(Money::sum(Money::fromMinor(-100, ngn())->allocate([1, 1, 1]))->minor)->toBe(-100);
});

it('gives the remainder to the largest fractional shares', function () {
    $parts = Money::fromMinor(5, ngn())->allocate([1, 1, 1]);

    expect(array_map(fn (Money $part): int => $part->minor, $parts))->toBe([2, 2, 1]);
});

it('rejects allocations it cannot satisfy', function (array $ratios) {
    Money::fromMinor(100, ngn())->allocate($ratios);
})->with([
    'no ratios' => [[]],
    'all zero' => [[0, 0]],
    'negative ratio' => [[1, -1]],
])->throws(InvalidArgumentException::class);

it('formats using its own currency symbol', function () {
    expect(Money::fromMinor(500000, ngn())->formatted())->toBe('₦5,000.00')
        ->and(Money::fromMinor(-15025, usd())->formatted())->toBe('-$150.25')
        ->and(Money::fromMinor(0, ngn())->formatted())->toBe('₦0.00')
        ->and(Money::fromMinor(5, ngn())->formatted())->toBe('₦0.05')
        ->and(Money::fromMinor(123456789, ngn())->formatted())->toBe('₦1,234,567.89');
});

it('stringifies to its formatted value', function () {
    expect((string) Money::fromMinor(500000, ngn()))->toBe('₦5,000.00');
});

it('serializes the integer, the currency and the display string', function () {
    expect(Money::fromMinor(500050, ngn())->jsonSerialize())
        ->toBe(['minor' => 500050, 'currency' => 'NGN', 'formatted' => '₦5,000.50']);
});

it('honours a currency with no minor unit', function () {
    config()->set('money.currencies.JPY', ['symbol' => '¥', 'scale' => 0, 'name' => 'Japanese Yen']);

    $yen = Currency::of('JPY');

    expect($yen->factor())->toBe(1)
        ->and(Money::fromMajor(5000, $yen)->minor)->toBe(5000)
        ->and(Money::fromMinor(5000, $yen)->formatted())->toBe('¥5,000')
        ->and(Money::parse('5,000', $yen)->minor)->toBe(5000);
});

it('rejects a fractional amount in a currency with no minor unit', function () {
    config()->set('money.currencies.JPY', ['symbol' => '¥', 'scale' => 0, 'name' => 'Japanese Yen']);

    Money::parse('5000.50', Currency::of('JPY'));
})->throws(InvalidArgumentException::class);
