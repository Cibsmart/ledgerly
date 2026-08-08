<?php

namespace Database\Factories;

use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Period>
 */
class PeriodFactory extends Factory
{
    protected $model = Period::class;

    /**
     * Defaults to January 2026.
     *
     * There is no randomised date here on purpose: periods must not overlap,
     * and a factory that picked a random month would produce a suite where two
     * periods occasionally collide and one test in fifty fails. Tests that need
     * more than one period say which months they mean, or use the `fiscalYear()`
     * helper in tests/Pest.php.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Date::create(2026, 1, 1)->startOfDay();

        return [
            'fiscal_year' => 2026,
            'name' => $start->format('F Y'),
            'start_date' => $start,
            'end_date' => $start->endOfMonth()->startOfDay(),
            'closed_at' => null,
            'closed_by' => null,
        ];
    }

    public function month(int $year, int $month): static
    {
        $start = Date::create($year, $month, 1)->startOfDay();

        return $this->state([
            'fiscal_year' => $year,
            'name' => $start->format('F Y'),
            'start_date' => $start,
            'end_date' => $start->endOfMonth()->startOfDay(),
        ]);
    }

    /**
     * A full fiscal year as one period, for tests that care about entries
     * rather than about month boundaries.
     */
    public function year(int $year): static
    {
        return $this->state([
            'fiscal_year' => $year,
            'name' => "FY {$year}",
            'start_date' => Date::create($year, 1, 1)->startOfDay(),
            'end_date' => Date::create($year, 12, 31)->startOfDay(),
        ]);
    }

    public function closed(?User $by = null): static
    {
        return $this->state(fn (): array => [
            'closed_at' => Date::now(),
            'closed_by' => ($by ?? User::factory()->create())->id,
        ]);
    }
}
