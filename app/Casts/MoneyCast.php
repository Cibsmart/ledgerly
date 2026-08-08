<?php

namespace App\Casts;

use App\Support\Currency;
use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts an integer minor-unit column to an App\Support\Money value object,
 * reading the currency from a sibling column on the same row.
 *
 * Declared with the currency column as the cast argument:
 *
 *     protected function casts(): array
 *     {
 *         return ['amount_minor' => Money::class.':currency_code'];
 *     }
 *
 * With no argument the column is assumed to be in the functional currency,
 * which is correct for translated amounts (`functional_amount_minor`) — those
 * are functional by definition and need no per-row currency column.
 *
 * TSet is `mixed` on purpose: set() is a runtime boundary — Eloquent hands it
 * whatever was assigned, including a float or a numeric string from a request.
 * Narrowing the generic would make the guard below look redundant to static
 * analysis while leaving the real hazard, a silently truncated float,
 * unguarded.
 *
 * @implements CastsAttributes<Money, mixed>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(protected ?string $currencyColumn = null) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromMinor((int) $value, $this->resolveCurrency($key, $attributes));
    }

    /**
     * Returns the minor units for this column, and — when a currency column is
     * configured — the currency code alongside it, so assigning a Money keeps
     * the amount and its currency in step. Writing one without the other is
     * how a ledger ends up with amounts nobody can interpret.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return $this->currencyColumn === null
                ? [$key => null]
                : [$key => null, $this->currencyColumn => null];
        }

        if (! $value instanceof Money) {
            if (is_int($value)) {
                // An integer carries no currency, so it is only safe where the
                // currency is already pinned — either by a sibling column that
                // is being set separately, or by the functional-currency default.
                return [$key => $value];
            }

            throw new InvalidArgumentException(
                "The [{$key}] attribute must be a Money instance or integer minor units."
            );
        }

        if ($this->currencyColumn === null) {
            $functional = Currency::functional();

            if (! $value->currency->equals($functional)) {
                throw new InvalidArgumentException(
                    "The [{$key}] attribute holds functional currency [{$functional->code}], "
                    ."but was given [{$value->currency->code}]."
                );
            }

            return [$key => $value->minor];
        }

        return [
            $key => $value->minor,
            $this->currencyColumn => $value->currency->code,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function resolveCurrency(string $key, array $attributes): Currency
    {
        if ($this->currencyColumn === null) {
            return Currency::functional();
        }

        $code = $attributes[$this->currencyColumn] ?? null;

        if (! is_string($code) || $code === '') {
            throw new InvalidArgumentException(
                "Cannot resolve the currency for [{$key}]: column [{$this->currencyColumn}] is missing or empty."
            );
        }

        return Currency::of($code);
    }
}
