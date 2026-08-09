<?php

namespace App\Http\Requests\ExchangeRates;

use App\Concerns\ExchangeRateValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExchangeRateStoreRequest extends FormRequest
{
    use ExchangeRateValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->exchangeRateRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->exchangeRateMessages();
    }
}
