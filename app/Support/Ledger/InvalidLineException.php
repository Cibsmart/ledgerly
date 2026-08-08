<?php

namespace App\Support\Ledger;

use App\Models\Account;
use App\Support\Currency;
use App\Support\Money;

/**
 * A single line is not postable as given.
 *
 * These are the checks that stop a nonsense line reaching the database, where
 * the CHECK constraints would reject it with a driver error naming a constraint
 * rather than a problem.
 */
final class InvalidLineException extends LedgerException
{
    public static function zeroAmount(?int $accountId): self
    {
        $where = $accountId === null ? 'A line' : "The line for account [{$accountId}]";

        return new self("{$where} has a zero amount. A zero line records nothing and is never correct.");
    }

    public static function negativeSide(string $side, Money $amount): self
    {
        return new self(
            "A {$side} takes a positive amount; {$amount->formatted()} was given. Sign is applied by "
            .'the debit()/credit() constructors — use PendingLine::signed() where the direction is '
            .'genuinely computed.'
        );
    }

    public static function unknownAccount(int $accountId): self
    {
        return new self("Account [{$accountId}] does not exist.");
    }

    public static function archivedAccount(Account $account): self
    {
        return new self(
            "Account [{$account->code} {$account->name}] is archived and cannot be posted to. "
            .'Reactivate it, or choose a different account.'
        );
    }

    public static function headerAccount(Account $account): self
    {
        return new self(
            "Account [{$account->code} {$account->name}] has child accounts and cannot be posted to "
            .'directly. Posting to a header double-counts it against its own children in any report '
            .'that sums a subtree — post to one of its children instead.'
        );
    }

    public static function currencyMismatch(Account $account, Currency $given): self
    {
        return new self(
            "Account [{$account->code} {$account->name}] is denominated in "
            ."[{$account->currency_code}] and cannot hold a [{$given->code}] line."
        );
    }

    public static function redundantRateOnFunctionalLine(Account $account, string $rate): self
    {
        return new self(
            "The line for [{$account->code} {$account->name}] is already in the functional currency, "
            ."so its exchange rate is 1; [{$rate}] was given. A functional line with any other rate "
            .'would translate an amount into something other than itself.'
        );
    }

    public static function vanishingAmount(Account $account, Money $amount, string $rate): self
    {
        return new self(
            "Translating {$amount->formatted()} for [{$account->code} {$account->name}] at rate "
            ."[{$rate}] rounds to zero in the functional currency. The line would contribute nothing "
            .'to the trial balance — check the rate is the right way round.'
        );
    }
}
