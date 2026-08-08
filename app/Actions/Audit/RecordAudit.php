<?php

namespace App\Actions\Audit;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Writes one entry to the audit trail.
 *
 * Storage is spatie/laravel-activitylog, but every ledger write goes through
 * this class rather than calling the activity() helper directly, for two
 * reasons. First, it is the single named entry point that
 * tests/Feature/Architecture/AuditCompletenessTest.php scans for — a global
 * helper scattered across the action layer would give that guardrail nothing to
 * hold on to. Second, and more important, `ActivityLogger::log()` returns null
 * and writes nothing when logging is disabled. A silent no-op is an acceptable
 * failure mode for a "who edited this blog post" log and an unacceptable one
 * for a ledger, so this converts it into an exception.
 *
 * Callers are responsible for placing the call inside the same transaction as
 * the change it describes; an audit row that survives a rolled-back posting is
 * worse than no audit row at all.
 */
final class RecordAudit
{
    /**
     * @param  array<string, mixed>  $properties  Context worth keeping: the before/after
     *                                            of what changed, the reason given, the
     *                                            source document. Stored as JSON.
     * @param  string|null  $description  Human-readable summary. Defaults to the event name.
     * @param  Model|null  $causer  Defaults to the authenticated user, and stays null for
     *                              console and scheduled runs, which have no user.
     * @param  string|null  $logName  Channel for filtering, e.g. 'ledger'. Defaults to the
     *                                configured default log name.
     */
    public function handle(
        AuditEvent $event,
        Model $subject,
        array $properties = [],
        ?string $description = null,
        ?Model $causer = null,
        ?string $logName = null,
    ): Activity {
        $activity = activity($logName)
            ->performedOn($subject)
            ->causedBy($causer)
            ->event($event->value)
            ->withProperties($properties)
            ->log($description ?? $event->value);

        if (! $activity instanceof Activity) {
            throw new RuntimeException(
                "Refusing to record [{$event->value}] without an audit trail: activity logging is "
                .'disabled. Unset ACTIVITYLOG_ENABLED — attribution is not optional in a ledger.'
            );
        }

        return $activity;
    }
}
