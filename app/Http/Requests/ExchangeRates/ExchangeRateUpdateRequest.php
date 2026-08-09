<?php

namespace App\Http\Requests\ExchangeRates;

use App\Concerns\ExchangeRateValidationRules;
use App\Models\ExchangeRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExchangeRateUpdateRequest extends FormRequest
{
    use ExchangeRateValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var ExchangeRate $exchangeRate */
        $exchangeRate = $this->route('exchange_rate');

        return $this->exchangeRateRules($exchangeRate);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->exchangeRateMessages();
    }
}
