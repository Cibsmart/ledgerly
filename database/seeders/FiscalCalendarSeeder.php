<?php

namespace Database\Seeders;

use App\Models\Period;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * Calendar-month periods for the current and next fiscal year.
 *
 * Periods are not ledger data, so this writes them directly rather than
 * through CreatePeriod — but it keeps that action's two rules: contiguous
 * months, and no overlap with anything already there. A gap in the calendar is
 * not corrupt, but nothing can be posted into it, which is a confusing failure
 * to discover mid-month.
 */
class FiscalCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $thisYear = (int) Date::now()->format('Y');

        foreach ([$thisYear, $thisYear + 1] as $year) {
            for ($month = 1; $month <= 12; $month++) {
                $start = Date::create($year, $month, 1)->startOfDay();
                $end = $start->endOfMonth()->startOfDay();

                if (Period::query()->overlapping($start, $end)->exists()) {
                    continue;
                }

                Period::query()->create([
                    'fiscal_year' => $year,
                    'name' => $start->format('F Y'),
                    'start_date' => $start,
                    'end_date' => $end,
                ]);
            }
        }
    }
}
