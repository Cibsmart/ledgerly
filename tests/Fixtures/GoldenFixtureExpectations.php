<?php

namespace Tests\Fixtures;

/**
 * The hand-verified figures for the golden fixture company.
 *
 * **These numbers were derived by hand from the entries in
 * database/seeders/GoldenFixtureSeeder.php, not read out of the application.**
 * That is the entire point: a test that computes the expected balance the same
 * way the code does can only ever assert that the code agrees with itself.
 *
 * Derivation of the two figures worth showing the work for:
 *
 * - **1015 Deel — USD.** Opening $2,000 at 1600 = ₦3,200,000; January revenue
 *   $2,500 at 1600 = ₦4,000,000; platform fee −$50 at 1600 = −₦80,000; transfer
 *   out −$1,000 at 1600 = −₦1,600,000; February revenue $3,000 at 1650 =
 *   ₦4,950,000; February net settlement $1,400 at 1650 = ₦2,310,000. Total
 *   ₦12,780,000 against a USD balance of $7,850. The functional total is *not*
 *   $7,850 × any single rate — that is the property this fixture exists to pin.
 *
 * - **2020 Due to Partners.** Booked at $3,600 × 1600 = ₦5,760,000; the first
 *   instalment releases $600 at the rate it was booked at, 1600 = ₦960,000.
 *   Remaining ₦4,800,000, i.e. $3,000 still owed. Releasing at February's 1650
 *   instead would leave ₦4,770,000 here and lose the ₦30,000 realized FX loss
 *   that belongs in 8100.
 *
 * If a test using this file fails, the fixture or the ledger is wrong. Adjust
 * these numbers only when the fixture's entries deliberately change.
 */
final class GoldenFixtureExpectations
{
    public const ENTRY_COUNT = 50;

    /**
     * Closing balance per account at 28 February 2026, in functional minor
     * units, signed debit-positive.
     *
     * @return array<string, int>
     */
    public static function trialBalance(): array
    {
        return [
            // Assets
            '1011' => 666_400_000,     // Zenith NGN
            '1013' => 158_000_000,     // Grey NGN
            '1015' => 1_278_000_000,   // Deel USD — see the derivation above
            '1016' => 120_000_000,     // Moniepoint NGN
            '1610' => 576_000_000,     // Computer & office equipment

            // 1014 Grey USD nets to zero: $1,000 in, $1,000 converted out.
            '1014' => 0,

            // Liabilities (credit balances, hence negative)
            '2020' => -480_000_000,    // Due to Partners — $3,000 of $3,600 left
            '2120' => -20_000_000,     // PAYE payable — February's, January remitted
            '2130' => -32_400_000,     // Pension payable — February's
            '2210' => 0,               // Salaries payable — both months settled

            // Equity
            '3200' => -940_000_000,    // Opening balance equity

            // Revenue
            '4010' => -2_015_000_000,  // Service revenue, gross

            // Expenses
            '5020' => 8_000_000,       // Platform fees
            '6010' => 360_000_000,     // Salaries and wages
            '6020' => 36_000_000,      // Employer pension
            '6100' => 80_000_000,      // Rent
            '6110' => 17_700_000,      // Utilities
            '6120' => 24_000_000,      // Internet
            '6130' => 19_000_000,      // Software
            '6140' => 40_000_000,      // Professional fees
            '6150' => 3_300_000,       // Bank charges
            '6160' => 39_000_000,      // Travel
            '6170' => 39_500_000,      // Marketing
            '6180' => 10_000_000,      // Office supplies
            '6190' => 7_500_000,       // Repairs
            '8100' => 5_000_000,       // FX loss: ₦20,000 conversion + ₦30,000 settlement
        ];
    }

    /**
     * Totals a Phase 3 statement must reproduce, in functional minor units.
     *
     * @return array<string, int>
     */
    public static function statementTotals(): array
    {
        return [
            'total_debits' => 3_487_400_000,
            'total_credits' => 3_487_400_000,

            'revenue' => 2_015_000_000,
            'expenses' => 689_000_000,
            'net_profit' => 1_326_000_000,

            'assets' => 2_798_400_000,
            'liabilities' => 532_400_000,
            'equity_before_profit' => 940_000_000,

            // Assets = Liabilities + Equity + Net profit.
            // 2,798,400,000 = 532,400,000 + 940,000,000 + 1,326,000,000
            'equity_including_profit' => 2_266_000_000,
        ];
    }

    /**
     * Closing balances in the *transaction* currency for the foreign-currency
     * accounts, which is what a bank statement will be reconciled against in
     * Phase 4 — and which no amount of functional-currency arithmetic can
     * recover on its own.
     *
     * @return array<string, array{currency: string, minor: int}>
     */
    public static function foreignCurrencyBalances(): array
    {
        return [
            '1015' => ['currency' => 'USD', 'minor' => 785_000],  // $7,850.00
            '1014' => ['currency' => 'USD', 'minor' => 0],        // $0.00
            '2020' => ['currency' => 'USD', 'minor' => -300_000], // $3,000.00 owed
        ];
    }
}
