<?php

namespace App\Support\Ledger;

use App\Models\Account;

/**
 * An opening balance run cannot be posted as given.
 */
final class OpeningBalanceException extends LedgerException
{
    public static function noEquityAccount(string $code): self
    {
        return new self(
            "The chart has no account [{$code}] (Opening Balance Equity), which is the contra side of "
            .'every opening balance. Seed the default chart, or add the account before entering '
            .'opening balances.'
        );
    }

    public static function equityAccountGiven(Account $equity): self
    {
        return new self(
            "[{$equity->code} {$equity->name}] is the balancing side of an opening balance run and is "
            .'computed, not entered. Remove it and enter only the accounts whose balances you are '
            .'opening.'
        );
    }

    public static function duplicateAccount(Account $account): self
    {
        return new self(
            "[{$account->code} {$account->name}] appears more than once. An opening balance is one "
            .'figure per account — combine the amounts into a single line.'
        );
    }

    public static function noBalances(): self
    {
        return new self('An opening balance run needs at least one account balance.');
    }
}
