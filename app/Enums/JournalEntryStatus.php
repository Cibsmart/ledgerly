<?php

namespace App\Enums;

/**
 * The lifecycle of a journal entry.
 *
 * There is deliberately no `reversed` status. Whether an entry has been
 * reversed is derived from the existence of another entry pointing at it
 * through `reverses_journal_entry_id` — a status column saying the same thing
 * would be a second source of truth, and keeping it in step would mean
 * updating a posted entry, which invariant 2 forbids.
 */
enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
        };
    }

    /**
     * Whether an entry in this status is part of the books.
     *
     * Only posted entries reach a trial balance; drafts are proposals.
     */
    public function isLedgerData(): bool
    {
        return $this === self::Posted;
    }
}
