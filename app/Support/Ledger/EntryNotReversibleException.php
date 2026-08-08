<?php

namespace App\Support\Ledger;

use App\Models\JournalEntry;

/**
 * The entry cannot be reversed.
 *
 * Reversing twice would double the correction and leave the books wrong in the
 * opposite direction, which is why `reverses_journal_entry_id` is unique — this
 * turns that unique violation into a sentence.
 */
final class EntryNotReversibleException extends LedgerException
{
    public static function notPosted(JournalEntry $entry): self
    {
        return new self(
            "Journal entry [{$entry->entry_number}] is a draft, not a posting, so there is nothing "
            .'to reverse. Delete the draft instead.'
        );
    }

    public static function alreadyReversed(JournalEntry $entry, JournalEntry $reversal): self
    {
        return new self(
            "Journal entry [{$entry->entry_number}] was already reversed by "
            ."[{$reversal->entry_number}]. Reversing it again would double the correction."
        );
    }
}
