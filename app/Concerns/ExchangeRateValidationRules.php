<?php

namespace App\Concerns;

use App\Models\ExchangeRate;
use App\Support\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * Validation for the exchange rate screen, shared by the store and update
 * requests.
 *
 * The rate is validated as a *string* against a decimal pattern rather than as
 * `numeric`. `numeric` would admit "1.65e3" and hand a float-ish value onward;
 * this pins the input to the plain decimal that gets stored, handed to bcmath,
 * and eventually read back by an auditor.
 *
 * @mixin FormRequest
 */
trait ExchangeRateValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function exchangeRateRules(?ExchangeRate $exchangeRate = null): array
    {
        $codes = array_keys(Currency::all());

        // On an update the pair comes from the row, because the request is
        // forbidden from carrying one.
        $base = $exchangeRate !== null ? $exchangeRate->base_code : $this->input('base_code');
        $quote = $exchangeRate !== null ? $exchangeRate->quote_code : $this->input('quote_code');

        return [
            // The pair is fixed once recorded: re-pointing a row at a different
            // pair is a new rate, not a correction of this one.
            'base_code' => $exchangeRate !== null
                ? ['prohibited']
                : ['required', 'string', 'size:3', Rule::in($codes)],

            'quote_code' => $exchangeRate !== null
                ? ['prohibited']
                : ['required', 'string', 'size:3', Rule::in($codes), 'different:base_code'],

            'rate' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,10})?$/',
                'not_in:0,0.0,0.00',
            ],

            // Mirrors UNIQUE (base_code, quote_code, effective_on). Effective
            // dating already means "until superseded", so a second row for the
            // same pair on the same day is a correction of the first, not an
            // addition to it — the screen offers Edit on the existing row.
            //
            // Hand-rolled rather than Rule::unique because the comparison has
            // to be whereDate. PostgreSQL stores a `date` column as a date;
            // sqlite has no date affinity, so Eloquent writes the full
            // "Y-m-d H:i:s" and a literal match on "2026-02-01" finds nothing —
            // the rule would pass on the test engine and fail at the database
            // on the production one. whereDate is also how
            // ExchangeRate::scopeEffectiveOn already reads this column.
            'effective_on' => [
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail) use ($base, $quote, $exchangeRate): void {
                    $clash = ExchangeRate::query()
                        ->where('base_code', $base)
                        ->where('quote_code', $quote)
                        ->whereDate('effective_on', Date::parse((string) $value)->toDateString())
                        ->when($exchangeRate !== null, fn ($query) => $query->whereKeyNot($exchangeRate))
                        ->exists();

                    if ($clash) {
                        $fail("A {$base}/{$quote} rate is already recorded for that date. Edit it instead.");
                    }
                },
            ],

            'source' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function exchangeRateMessages(): array
    {
        return [
            'rate.regex' => 'The rate must be a plain decimal with at most 10 decimal places, e.g. 1650.25.',
            'rate.not_in' => 'The rate must be greater than zero.',
            'quote_code.different' => 'A currency cannot have a rate against itself.',
            'base_code.prohibited' => 'The currency pair cannot be changed. Delete this rate and record the correct one.',
            'quote_code.prohibited' => 'The currency pair cannot be changed. Delete this rate and record the correct one.',
        ];
    }
}
