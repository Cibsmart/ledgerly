<?php

namespace App\Http\Requests\OpeningBalances;

use App\Support\Currency;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Validation for an opening balance run.
 *
 * A convenience that produces good field errors, not the guarantee. Everything
 * that matters — the account exists, is postable, is not archived, accepts this
 * currency, and the whole entry balances — is re-checked by PostEntry, because
 * a request is only one of the ways a balance gets posted.
 */
class OpeningBalanceStoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'lines.*.side' => ['required', Rule::in(['debit', 'credit'])],
            'lines.*.currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::in(array_keys(Currency::all())),
            ],
            // Parsed against the line's currency in after(), which is the only
            // place that knows how many decimal places are legal.
            'lines.*.amount' => ['required', 'string', 'max:30'],
            // Null means "use the exchange_rates table"; a value here is the
            // rate this balance was actually struck at (D8).
            'lines.*.exchange_rate' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,10})?$/', 'not_in:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Enter at least one account balance.',
            'lines.*.exchange_rate.regex' => 'The rate must be a plain decimal with at most 10 decimal places.',
            'lines.*.exchange_rate.not_in' => 'The rate must be greater than zero.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, string|null>> $lines */
                $lines = $this->input('lines', []);

                foreach ($lines as $index => $line) {
                    $amount = $line['amount'] ?? null;
                    $code = $line['currency_code'] ?? null;

                    if (! is_string($amount) || ! is_string($code) || $validator->errors()->has("lines.{$index}.currency_code")) {
                        continue;
                    }

                    try {
                        $parsed = Money::parse($amount, Currency::of($code));
                    } catch (InvalidArgumentException) {
                        $validator->errors()->add(
                            "lines.{$index}.amount",
                            "Enter a valid {$code} amount, e.g. 1500.00."
                        );

                        continue;
                    }

                    if ($parsed->isZero()) {
                        $validator->errors()->add(
                            "lines.{$index}.amount",
                            'A zero opening balance records nothing. Remove the line instead.'
                        );
                    }

                    if ($parsed->isNegative()) {
                        $validator->errors()->add(
                            "lines.{$index}.amount",
                            'Enter a positive amount and choose the debit or credit side.'
                        );
                    }
                }
            },
        ];
    }
}
