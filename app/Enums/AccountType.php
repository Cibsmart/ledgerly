<?php

namespace App\Enums;

/**
 * The five account classifications every chart of accounts is built from.
 *
 * The `code` ranges in docs/plans/chart-of-accounts.md map onto these, but the
 * type is stored explicitly rather than inferred from the code — renumbering an
 * account must never silently reclassify it.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expense => 'Expense',
        };
    }

    /**
     * The side this type's balance naturally falls on.
     *
     * Deliberately derived rather than stored — see NormalBalance.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }

    /**
     * Whether balances of this type carry forward across fiscal years.
     *
     * The complement — the income statement types — are the ones the year-end
     * closing entry (D2) zeroes into Retained Earnings.
     */
    public function isBalanceSheet(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Revenue, self::Expense => false,
        };
    }

    public function isIncomeStatement(): bool
    {
        return ! $this->isBalanceSheet();
    }

    /**
     * Report ordering, matching the sequence a balance sheet and income
     * statement are conventionally read in.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Asset => 1,
            self::Liability => 2,
            self::Equity => 3,
            self::Revenue => 4,
            self::Expense => 5,
        };
    }
}
