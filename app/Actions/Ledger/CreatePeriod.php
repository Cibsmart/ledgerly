<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\Period;
use App\Models\User;
use App\Support\Ledger\PeriodNotFoundException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Create a fiscal period.
 *
 * Periods must not overlap: an entry date resolves to its period by an
 * inclusive BETWEEN, so two periods covering the same day would make that
 * lookup ambiguous and the period an entry lands in a matter of row order.
 *
 * Non-overlap is asserted here rather than in the schema because expressing it
 * needs a PostgreSQL exclusion constraint over a daterange, which sqlite cannot
 * mirror — and a rule enforced on only one of the two test engines is worse
 * than one enforced on neither, because it hides the gap.
 */
final class CreatePeriod
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(
        int $fiscalYear,
        string $name,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?User $user = null,
    ): Period {
        $start = Date::parse($startDate)->startOfDay();
        $end = Date::parse($endDate)->startOfDay();
        $actor = $user ?? Auth::user();

        if ($start->greaterThan($end)) {
            throw new PeriodNotFoundException(
                "Period [{$name}] starts on {$start->toDateString()}, after it ends on "
                ."{$end->toDateString()}."
            );
        }

        return DB::transaction(function () use ($fiscalYear, $name, $start, $end, $actor): Period {
            if (Period::query()->overlapping($start, $end)->lockForUpdate()->exists()) {
                throw PeriodNotFoundException::overlapping($start, $end);
            }

            $period = Period::query()->create([
                'fiscal_year' => $fiscalYear,
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
            ]);

            $this->audit->handle(
                event: AuditEvent::Created,
                subject: $period,
                properties: [
                    'period' => $period->name,
                    'fiscal_year' => $period->fiscal_year,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ],
                description: "Created period {$period->name}",
                causer: $actor,
                logName: 'ledger',
            );

            return $period;
        });
    }

    /**
     * Create the twelve calendar-month periods of a fiscal year.
     *
     * Contiguity is achieved by generating a whole year at once rather than by
     * a constraint: a gap between periods is not corrupt data, but nothing can
     * be posted into it, which is a confusing failure to hit in February.
     *
     * @return list<Period>
     */
    public function forFiscalYear(int $fiscalYear, ?User $user = null): array
    {
        $periods = [];

        for ($month = 1; $month <= 12; $month++) {
            $start = Date::create($fiscalYear, $month, 1)->startOfDay();

            $periods[] = $this->handle(
                fiscalYear: $fiscalYear,
                name: $start->format('F Y'),
                startDate: $start,
                endDate: $start->endOfMonth()->startOfDay(),
                user: $user,
            );
        }

        return $periods;
    }
}
