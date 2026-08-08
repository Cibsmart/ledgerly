<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base_code' => 'USD',
            'quote_code' => Currency::functional()->code,
            'rate' => '1600.0000000000',
            'effective_on' => Date::create(2026, 1, 1)->startOfDay(),
            'source' => 'factory',
            'created_by' => null,
        ];
    }

    public function pair(string $base, string $quote): static
    {
        return $this->state([
            'base_code' => strtoupper($base),
            'quote_code' => strtoupper($quote),
        ]);
    }

    public function at(string $rate): static
    {
        return $this->state(['rate' => $rate]);
    }

    public function effectiveOn(CarbonInterface|string $date): static
    {
        return $this->state(['effective_on' => Date::parse($date)->startOfDay()]);
    }
}
