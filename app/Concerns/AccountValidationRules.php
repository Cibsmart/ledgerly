<?php

namespace App\Concerns;

use App\Enums\AccountType;
use App\Enums\CashFlowClassification;
use App\Models\Account;
use App\Support\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validation for the chart of accounts, shared by the store and update
 * requests.
 *
 * These are conveniences that produce a good error message. They are not the
 * guarantee: the actions re-check anything that could strand posted history,
 * because a request is only one of the ways an account gets written.
 */
trait AccountValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function accountRules(?Account $account = null): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9][0-9A-Za-z-]*$/',
                Rule::unique('accounts', 'code')->ignore($account),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id'),
                // Guarded properly in UpdateAccount, which also catches deeper
                // cycles; this only makes the obvious case a field error.
                Rule::notIn(array_filter([$account?->id])),
            ],
            // Null means the account accepts any currency, which is the norm
            // for everything except a bank or platform balance.
            'currency_code' => ['nullable', 'string', 'size:3', Rule::in(array_keys(Currency::all()))],
            'is_monetary' => ['required', 'boolean'],
            'cash_flow_classification' => ['nullable', Rule::enum(CashFlowClassification::class)],
            'is_cash_equivalent' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
