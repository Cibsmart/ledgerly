<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\Period;
use App\Models\User;
use App\Support\Ledger\ClosedPeriodException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reopen a closed period.
 *
 * Restricted to administrators by PeriodPolicy: closing is routine month-end
 * work, but reopening unlocks a period that has already been reported on, and
 * anything posted into it afterwards changes figures someone has seen. The
 * reason is required rather than optional for the same reason — this is the
 * audit entry a later question will be answered from.
 */
final class ReopenPeriod
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Period $period, string $reason, ?User $user = null): Period
    {
        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($period, $reason, $actor): Period {
            /** @var Period $locked */
            $locked = Period::query()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isOpen()) {
                throw ClosedPeriodException::notClosed($locked);
            }

            $closedAt = $locked->closed_at;
            $closedBy = $locked->closed_by;

            $locked->closed_at = null;
            $locked->closed_by = null;
            $locked->save();

            $this->audit->handle(
                event: AuditEvent::Reopened,
                subject: $locked,
                properties: [
                    'period' => $locked->name,
                    'fiscal_year' => $locked->fiscal_year,
                    'reason' => $reason,
                    'previously_closed_at' => $closedAt?->toIso8601String(),
                    'previously_closed_by' => $closedBy,
                ],
                description: "Reopened period {$locked->name}",
                causer: $actor,
                logName: 'ledger',
            );

            return $locked->refresh();
        });
    }
}
