<?php

namespace App\Support\Ledger;

use App\Models\Account;
use App\Support\Money;

/**
 * One line of an entry that has not been posted yet.
 *
 * Amounts are signed with debit positive, matching storage — but callers
 * almost always want the debit()/credit() constructors, which take a positive
 * amount and apply the sign. Writing `credit($revenue, money(-1000))` is how a
 * sign error gets in.
 *
 * `exchangeRate` is the D8 override: the rate this line's conversion actually
 * executed at, which takes precedence over the `exchange_rates` table. Null
 * means "use the table", and for a functional-currency line means a rate of 1.
 */
final readonly class PendingLine
{
    private function __construct(
        public int $accountId,
        public Money $amount,
        public ?string $exchangeRate = null,
        public ?string $memo = null,
    ) {}

    /**
     * A debit of a positive amount.
     */
    public static function debit(
        Account|int $account,
        Money $amount,
        ?string $exchangeRate = null,
        ?string $memo = null,
    ): self {
        return new self(
            self::identify($account),
            self::assertPositive($amount, 'debit'),
            $exchangeRate,
            $memo,
        );
    }

    /**
     * A credit of a positive amount, stored negated.
     */
    public static function credit(
        Account|int $account,
        Money $amount,
        ?string $exchangeRate = null,
        ?string $memo = null,
    ): self {
        return new self(
            self::identify($account),
            self::assertPositive($amount, 'credit')->negate(),
            $exchangeRate,
            $memo,
        );
    }

    /**
     * A line whose sign is already applied — debit positive, credit negative.
     *
     * Used where the sign is computed rather than chosen, most notably by
     * ReverseEntry negating an existing line, and by FX residuals whose
     * direction is only known once the arithmetic is done.
     */
    public static function signed(
        Account|int $account,
        Money $amount,
        ?string $exchangeRate = null,
        ?string $memo = null,
    ): self {
        if ($amount->isZero()) {
            throw InvalidLineException::zeroAmount(self::identify($account));
        }

        return new self(self::identify($account), $amount, $exchangeRate, $memo);
    }

    public function isDebit(): bool
    {
        return $this->amount->isPositive();
    }

    public function isCredit(): bool
    {
        return $this->amount->isNegative();
    }

    private static function identify(Account|int $account): int
    {
        return $account instanceof Account ? $account->id : $account;
    }

    private static function assertPositive(Money $amount, string $side): Money
    {
        if ($amount->isZero()) {
            throw InvalidLineException::zeroAmount(null);
        }

        if ($amount->isNegative()) {
            throw InvalidLineException::negativeSide($side, $amount);
        }

        return $amount;
    }
}
