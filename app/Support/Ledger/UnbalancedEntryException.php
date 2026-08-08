<?php

namespace App\Support\Ledger;

use App\Support\Money;

/**
 * Invariant 1: debits must equal credits, in the functional currency.
 *
 * Thrown by PostEntry before anything touches the database. PostgreSQL's
 * deferred constraint would catch the same thing at COMMIT, but a constraint
 * violation surfaces as a driver error with no useful context — this carries
 * the imbalance itself, which is usually enough to see what went wrong.
 *
 * The most common cause is not arithmetic but a missing FX gain/loss line: a
 * two-currency entry (a Grey USD→NGN conversion, say) does not net to zero in
 * functional terms on its own, and needs the residual posted explicitly.
 */
final class UnbalancedEntryException extends LedgerException
{
    public static function by(Money $imbalance): self
    {
        $direction = $imbalance->isPositive() ? 'debits exceed credits' : 'credits exceed debits';

        return new self(
            "Entry does not balance: {$direction} by {$imbalance->absolute()->formatted()} "
            ."({$imbalance->currency->code}). Debits and credits must be equal in the functional "
            .'currency. A mixed-currency entry needs an explicit FX gain/loss line for the residual.'
        );
    }

    public static function tooFewLines(int $count): self
    {
        return new self(
            "An entry needs at least two lines to balance; {$count} given."
        );
    }
}
