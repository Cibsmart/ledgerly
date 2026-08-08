<?php

namespace App\Actions\Ledger;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\Period;
use App\Models\User;
use App\Support\Ledger\ClosedPeriodException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Close a fiscal period, after which nothing may be posted into it.
 *
 * The row lock is the point of the transaction. PostEntry locks the same row
 * before it resolves a period, so a close and a posting can never interleave:
 * either the posting completes and is inside the closed period, or the close
 * completes and the posting is rejected. Without the lock both read an open
 * period and the entry lands in a period that is closed by the time it commits.
 */
final class ClosePeriod
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Period $period, ?User $user = null, ?string $note = null): Period
    {
        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($period, $actor, $note): Period {
            /** @var Period $locked */
            $locked = Period::query()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isClosed()) {
                throw ClosedPeriodException::alreadyClosed($locked);
            }

            $locked->closed_at = Date::now();
            $locked->closed_by = $actor?->id;
            $locked->save();

            $this->audit->handle(
                event: AuditEvent::Closed,
                subject: $locked,
                properties: array_filter([
                    'period' => $locked->name,
                    'fiscal_year' => $locked->fiscal_year,
                    'start_date' => $locked->start_date->toDateString(),
                    'end_date' => $locked->end_date->toDateString(),
                    'entry_count' => $locked->entries()->count(),
                    'note' => $note,
                ], fn (mixed $value): bool => $value !== null),
                description: "Closed period {$locked->name}",
                causer: $actor,
                logName: 'ledger',
            );

            return $locked->refresh();
        });
    }
}
