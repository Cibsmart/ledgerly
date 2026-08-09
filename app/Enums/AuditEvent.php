<?php

namespace App\Enums;

/**
 * The vocabulary of auditable events.
 *
 * Accounting invariant 5 says everything is attributable, which is only useful
 * if the event names are stable enough to query. Spatie's own ActivityEvent
 * enum covers the four Eloquent lifecycle events; the ledger's vocabulary is
 * wider, because the events worth auditing here are domain events (an entry was
 * posted, a period was closed) rather than row mutations. Posted entries are
 * immutable, so `Updated` and `Deleted` will never describe one.
 */
enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Archived = 'archived';
    case Restored = 'restored';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Deleted => 'Deleted',
            self::Archived => 'Archived',
            self::Restored => 'Restored',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
            self::Closed => 'Closed',
            self::Reopened => 'Reopened',
        };
    }
}
