<?php

namespace App\Support;

use App\Casts\MoneyCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An immutable monetary amount: an integer number of minor units, plus the
 * currency those units are denominated in.
 *
 * Money never touches a float. Integer arithmetic is exact and behaves
 * identically on SQLite and PostgreSQL, which is what lets the test suite run
 * against `:memory:` without the NUMERIC type-affinity hazard a decimal column
 * would introduce.
 *
 * **Arithmetic across currencies throws.** Adding USD to NGN is never a
 * rounding problem to be smoothed over — it is a missing conversion, and the
 * only correct response is to fail loudly. Use convertTo() with an explicit
 * rate to bring amounts into a common currency first.
 */
final readonly class Money implements Castable, JsonSerializable, Stringable
{
    public function __construct(public int $minor, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public static function fromMinor(int $minor, Currency $currency): self
    {
        return new self($minor, $currency);
    }

    /**
     * Build from a whole major unit, e.g. `fromMajor(5, NGN)` is ₦5.00.
     */
    public static function fromMajor(int $major, Currency $currency): self
    {
        return new self($major * $currency->factor(), $currency);
    }

    /**
     * Parse a user-entered amount such as "5,000", "5000.50", or "₦5,000.00".
     *
     * Rejects anything with more decimal places than the currency subdivides
     * into, rather than silently rounding a value the user typed.
     */
    public static function parse(string $amount, Currency $currency): self
    {
        $normalized = str_replace([$currency->symbol, $currency->code, ',', ' ', "\u{00A0}"], '', trim($amount));

        $pattern = $currency->scale > 0
            ? '/^(-?)(\d+)(?:\.(\d{1,'.$currency->scale.'}))?$/'
            : '/^(-?)(\d+)$/';

        if (! preg_match($pattern, $normalized, $matches)) {
            throw new InvalidArgumentException("Invalid {$currency->code} amount [{$amount}].");
        }

        $fraction = str_pad($matches[3] ?? '', $currency->scale, '0');
        $minor = ((int) $matches[2]) * $currency->factor() + (int) ($fraction === '' ? '0' : $fraction);

        return new self($matches[1] === '-' ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other, 'add');

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other, 'subtract');

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    /**
     * Translate this amount into another currency at an explicit rate.
     *
     * The rate is a decimal string expressing how many units of the target
     * currency one unit of this currency buys, e.g. "1650.25" converting USD
     * to NGN. It is passed in rather than looked up so that the rate used is
     * always an auditable input to the posting, never re-derived later — a
     * posted entry's translated amounts must never move because a rate table
     * was corrected afterwards.
     *
     * Rounds half away from zero, the conventional accounting rounding.
     * bcmath is used only here, at the conversion boundary; ledger arithmetic
     * remains integer.
     */
    public function convertTo(Currency $target, string $rate): self
    {
        $normalized = trim($rate);

        // is_numeric alone would admit "1e5" and " 0x1A"; the pattern pins the
        // rate to a plain decimal so what is stored is what an auditor reads.
        if (! is_numeric($normalized) || ! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException("Invalid exchange rate [{$rate}].");
        }

        $precision = 12;

        $value = bcmul((string) $this->minor, $normalized, $precision);
        $value = bcmul($value, (string) $target->factor(), $precision);
        $value = bcdiv($value, (string) $this->currency->factor(), $precision);

        // bcadd at scale 0 truncates toward zero, so nudging by half a unit in
        // the value's own direction yields half-away-from-zero rounding.
        $rounded = bcadd($value, bccomp($value, '0', $precision) < 0 ? '-0.5' : '0.5', 0);

        return new self((int) $rounded, $target);
    }

    /**
     * Split this amount across the given integer ratios using the
     * largest-remainder method, so the parts always re-sum to the original.
     *
     * Required by the plan's rounding rule: percentage allocations and splits
     * must never leak or invent a minor unit.
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Cannot allocate money across zero ratios.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Allocation ratios cannot be negative.');
            }
        }

        $total = array_sum($ratios);

        if ($total === 0) {
            throw new InvalidArgumentException('Allocation ratios must not sum to zero.');
        }

        // Floor each share, then hand the undistributed remainder to the shares
        // with the largest fractional parts, breaking ties by original order.
        $shares = [];
        $remainders = [];
        $distributed = 0;

        foreach ($ratios as $index => $ratio) {
            $exact = $this->minor * $ratio;
            $share = intdiv($exact, $total);

            $shares[$index] = $share;
            $remainders[$index] = $exact - ($share * $total);
            $distributed += $share;
        }

        $leftover = $this->minor - $distributed;
        $step = $leftover < 0 ? -1 : 1;

        arsort($remainders);

        while ($leftover !== 0) {
            foreach (array_keys($remainders) as $index) {
                if ($leftover === 0) {
                    break;
                }

                $shares[$index] += $step;
                $leftover -= $step;
            }
        }

        ksort($shares);

        return array_values(array_map(
            fn (int $minor): self => new self($minor, $this->currency),
            $shares
        ));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other, 'compare');

        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other, 'compare');

        return $this->minor < $other->minor;
    }

    /**
     * Sum amounts that are all in the same currency.
     *
     * The currency must be given explicitly when the list may be empty, since
     * an empty sum has no currency to infer.
     *
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts, ?Currency $currency = null): self
    {
        $total = 0;
        $resolved = $currency;

        foreach ($amounts as $amount) {
            if ($resolved === null) {
                $resolved = $amount->currency;
            }

            if (! $amount->currency->equals($resolved)) {
                throw new InvalidArgumentException(
                    "Cannot sum mixed currencies [{$resolved->code}] and [{$amount->currency->code}]."
                );
            }

            $total += $amount->minor;
        }

        if ($resolved === null) {
            throw new InvalidArgumentException('Cannot sum an empty set of amounts without an explicit currency.');
        }

        return new self($total, $resolved);
    }

    /**
     * Format for display, e.g. "₦5,000.00" or "-$150.25".
     */
    public function formatted(): string
    {
        $absolute = abs($this->minor);
        $factor = $this->currency->factor();

        return sprintf(
            '%s%s%s%s',
            $this->minor < 0 ? '-' : '',
            $this->currency->symbol,
            number_format(intdiv($absolute, $factor)),
            $this->currency->scale > 0
                ? '.'.str_pad((string) ($absolute % $factor), $this->currency->scale, '0', STR_PAD_LEFT)
                : '',
        );
    }

    public function __toString(): string
    {
        return $this->formatted();
    }

    /**
     * Serialized for Inertia. The frontend formats and displays; it never
     * computes, so it receives the exact integer, the currency, and the
     * display string.
     *
     * @return array{minor: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency->code,
            'formatted' => $this->formatted(),
        ];
    }

    /**
     * Cast a money column, reading its currency from a sibling column.
     *
     * Declared as `Money::class.':currency_code'` in a model's casts().
     *
     * @param  array<array-key, mixed>  $arguments
     * @return class-string<MoneyCast>
     */
    public static function castUsing(array $arguments): string
    {
        return MoneyCast::class;
    }

    private function assertSameCurrency(self $other, string $operation): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new InvalidArgumentException(
                "Cannot {$operation} [{$other->currency->code}] and [{$this->currency->code}]; "
                .'convert to a common currency first.'
            );
        }
    }
}
