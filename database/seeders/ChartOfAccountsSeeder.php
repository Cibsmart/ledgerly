<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\CashFlowClassification;
use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The default chart of accounts, from docs/plans/chart-of-accounts.md.
 *
 * Three properties this seeder is responsible for:
 *
 * 1. **`is_monetary` is populated on every account.** Phase 5 revaluation reads
 *    it, and retrofitting it means re-classifying the whole chart by hand.
 *    Getting it wrong on a USD balance misstates the year-end balance sheet;
 *    getting it wrong on a non-monetary one invents FX movement that never
 *    happened.
 * 2. **`cash_flow_classification` is populated on every account** for the same
 *    reason — a half-classified chart produces a cash flow statement that
 *    silently omits whatever was missed.
 * 3. **It is idempotent**, keyed on `code`, so it can be re-run to pick up new
 *    accounts without disturbing existing ones or their history.
 *
 * Two codes are fixed by decision and are not free to move: `7100 FX Gain` and
 * `8100 FX Loss`, which ConvertCurrency (D9) will post its residual to by code.
 *
 * Code 1018 is deliberately unassigned. R2 says eight account-currency pairs
 * but enumerates seven (Zenith ×2, Grey ×2, Deel, Moniepoint, Kuda); rather
 * than invent an eighth institution, the block is left free.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as $account) {
            Account::query()->updateOrCreate(
                ['code' => $account['code']],
                [
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'parent_id' => isset($account['parent'])
                        ? Account::query()->where('code', $account['parent'])->value('id')
                        : null,
                    'currency_code' => $account['currency'] ?? null,
                    'is_monetary' => $account['monetary'],
                    'cash_flow_classification' => $account['cash_flow'],
                    'is_cash_equivalent' => $account['cash_equivalent'] ?? false,
                    'is_active' => true,
                    'description' => $account['description'] ?? null,
                ],
            );
        }
    }

    /**
     * Ordered parent-before-child, so parent_id always resolves.
     *
     * @return list<array{
     *     code: string,
     *     name: string,
     *     type: AccountType,
     *     monetary: bool,
     *     cash_flow: CashFlowClassification|null,
     *     parent?: string,
     *     currency?: string,
     *     cash_equivalent?: bool,
     *     description?: string
     * }>
     */
    private function accounts(): array
    {
        $asset = AccountType::Asset;
        $liability = AccountType::Liability;
        $equity = AccountType::Equity;
        $revenue = AccountType::Revenue;
        $expense = AccountType::Expense;

        $operating = CashFlowClassification::Operating;
        $investing = CashFlowClassification::Investing;
        $financing = CashFlowClassification::Financing;

        return [
            // ---------------------------------------------------------------
            // Assets — current
            // ---------------------------------------------------------------
            ['code' => '1000', 'name' => 'Current Assets', 'type' => $asset, 'monetary' => false, 'cash_flow' => null],

            // Cash and bank: one GL account per financial account (R2). These
            // are what the cash flow statement reconciles *to*, so they carry
            // no classification of their own.
            ['code' => '1010', 'name' => 'Cash and Bank', 'type' => $asset, 'parent' => '1000', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true],
            ['code' => '1011', 'name' => 'Zenith Bank — NGN', 'type' => $asset, 'parent' => '1010', 'currency' => 'NGN', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true],
            ['code' => '1012', 'name' => 'Zenith Bank — USD', 'type' => $asset, 'parent' => '1010', 'currency' => 'USD', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true],
            ['code' => '1013', 'name' => 'Grey — NGN', 'type' => $asset, 'parent' => '1010', 'currency' => 'NGN', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true],
            ['code' => '1014', 'name' => 'Grey — USD', 'type' => $asset, 'parent' => '1010', 'currency' => 'USD', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true, 'description' => 'A move between 1014 and 1013 is an FX conversion (D9), not a transfer.'],
            ['code' => '1015', 'name' => 'Deel — USD', 'type' => $asset, 'parent' => '1010', 'currency' => 'USD', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true, 'description' => 'Receipts arrive net of platform fees, which are booked to 5020 rather than reducing revenue.'],
            ['code' => '1016', 'name' => 'Moniepoint — NGN', 'type' => $asset, 'parent' => '1010', 'currency' => 'NGN', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true],
            ['code' => '1017', 'name' => 'Kuda — NGN', 'type' => $asset, 'parent' => '1010', 'currency' => 'NGN', 'monetary' => true, 'cash_flow' => null, 'cash_equivalent' => true],

            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => $asset, 'parent' => '1000', 'monetary' => true, 'cash_flow' => $operating, 'description' => 'AR control account. The sub-ledger is contact_id on the line, not a GL account per partner.'],
            ['code' => '1200', 'name' => 'Prepayments', 'type' => $asset, 'parent' => '1000', 'monetary' => false, 'cash_flow' => $operating, 'description' => 'A right to goods or services, not to currency — hence non-monetary.'],
            ['code' => '1300', 'name' => 'WHT Receivable', 'type' => $asset, 'parent' => '1000', 'monetary' => true, 'cash_flow' => $operating, 'description' => 'Tax withheld on receipts is an asset creditable against CIT, never a cost.'],
            ['code' => '1310', 'name' => 'Input VAT — Recoverable', 'type' => $asset, 'parent' => '1000', 'monetary' => true, 'cash_flow' => $operating, 'description' => 'Recoverable input VAT only; the rest is expensed to 6210. Confirm the split with the accountant before Phase 6 (D5a).'],

            ['code' => '1400', 'name' => 'Short-term Investments', 'type' => $asset, 'parent' => '1000', 'monetary' => false, 'cash_flow' => $investing],
            ['code' => '1410', 'name' => 'Treasury Bills — at amortised cost', 'type' => $asset, 'parent' => '1400', 'monetary' => true, 'cash_flow' => $investing, 'description' => 'Single carrying value: debited at cost, accretion debits here and credits 7200, maturity clears to cash.'],
            ['code' => '1420', 'name' => 'Accrued Interest Receivable', 'type' => $asset, 'parent' => '1400', 'monetary' => true, 'cash_flow' => $investing],

            // ---------------------------------------------------------------
            // Assets — non-current
            // ---------------------------------------------------------------
            ['code' => '1500', 'name' => 'Non-current Investments', 'type' => $asset, 'monetary' => false, 'cash_flow' => $investing],
            ['code' => '1510', 'name' => 'Bonds — at amortised cost', 'type' => $asset, 'parent' => '1500', 'monetary' => true, 'cash_flow' => $investing],
            ['code' => '1520', 'name' => 'Equity Investments — at cost', 'type' => $asset, 'parent' => '1500', 'monetary' => false, 'cash_flow' => $investing, 'description' => 'Shares are not a claim to a fixed number of currency units, so non-monetary. Carried at cost (D16).'],

            ['code' => '1600', 'name' => 'Property, Plant & Equipment', 'type' => $asset, 'monetary' => false, 'cash_flow' => $investing],
            ['code' => '1610', 'name' => 'Computer & Office Equipment — cost', 'type' => $asset, 'parent' => '1600', 'monetary' => false, 'cash_flow' => $investing],
            ['code' => '1615', 'name' => 'Accumulated Depreciation — Computer & Office Equipment', 'type' => $asset, 'parent' => '1600', 'monetary' => false, 'cash_flow' => $investing, 'description' => 'Contra-asset: an Asset with a natural credit balance, presented as a deduction rather than sorted by sign.'],
            ['code' => '1620', 'name' => 'Land — cost', 'type' => $asset, 'parent' => '1600', 'monetary' => false, 'cash_flow' => $investing, 'description' => 'Not depreciated — kept separate from buildings for that reason.'],
            ['code' => '1630', 'name' => 'Buildings — cost', 'type' => $asset, 'parent' => '1600', 'monetary' => false, 'cash_flow' => $investing],
            ['code' => '1635', 'name' => 'Accumulated Depreciation — Buildings', 'type' => $asset, 'parent' => '1600', 'monetary' => false, 'cash_flow' => $investing, 'description' => 'Contra-asset.'],

            // ---------------------------------------------------------------
            // Liabilities
            // ---------------------------------------------------------------
            ['code' => '2000', 'name' => 'Current Liabilities', 'type' => $liability, 'monetary' => false, 'cash_flow' => null],
            ['code' => '2010', 'name' => 'Accounts Payable', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating],
            ['code' => '2020', 'name' => 'Due to Partners', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating, 'description' => 'R8: partner-funded hardware recovered by instalment. Currency-agnostic so partners can share the control account; is_monetary is what drives its year-end revaluation.'],
            ['code' => '2100', 'name' => 'Output VAT', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating],
            ['code' => '2110', 'name' => 'WHT Payable', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating, 'description' => 'Withheld from contractors, owed to FIRS.'],
            ['code' => '2120', 'name' => 'PAYE Payable', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating],
            ['code' => '2130', 'name' => 'Pension Payable', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating, 'description' => 'Employee and employer portions both settle here.'],
            ['code' => '2140', 'name' => 'NHF Payable', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating],
            ['code' => '2200', 'name' => 'Accrued Expenses', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating],
            ['code' => '2210', 'name' => 'Salaries & Wages Payable', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating, 'description' => 'Net pay owed between the payroll run and payment.'],
            ['code' => '2300', 'name' => 'Company Income Tax Payable', 'type' => $liability, 'parent' => '2000', 'monetary' => true, 'cash_flow' => $operating],

            // ---------------------------------------------------------------
            // Equity — all non-monetary, all financing
            // ---------------------------------------------------------------
            ['code' => '3000', 'name' => 'Share Capital', 'type' => $equity, 'monetary' => false, 'cash_flow' => $financing],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => $equity, 'monetary' => false, 'cash_flow' => $financing, 'description' => 'D2: the year-end closing entry posts revenue and expense balances here.'],
            ['code' => '3200', 'name' => 'Opening Balance Equity', 'type' => $equity, 'monetary' => false, 'cash_flow' => $financing, 'description' => 'The contra side of opening balances. A non-zero balance means opening balances are not finished — which is why it stays a real account rather than being netted into 3100.'],
            ['code' => '3300', 'name' => 'Dividends & Distributions', 'type' => $equity, 'monetary' => false, 'cash_flow' => $financing, 'description' => 'Closed into 3100 at year end.'],

            // There is deliberately no "Current Year Earnings" account: it would
            // be a stored statement figure, which invariant 3 forbids.

            // ---------------------------------------------------------------
            // Revenue
            // ---------------------------------------------------------------
            ['code' => '4000', 'name' => 'Operating Revenue', 'type' => $revenue, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '4010', 'name' => 'Service Revenue', 'type' => $revenue, 'parent' => '4000', 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Gross, before platform fees and before any partner deduction. Which partner is contact_id on the line.'],
            ['code' => '4020', 'name' => 'Rental Income', 'type' => $revenue, 'parent' => '4000', 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '4090', 'name' => 'Other Operating Income', 'type' => $revenue, 'parent' => '4000', 'monetary' => false, 'cash_flow' => $operating],

            // ---------------------------------------------------------------
            // Direct costs
            // ---------------------------------------------------------------
            ['code' => '5010', 'name' => 'Contractor Costs', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Distinct from payroll; the WHT side posts to 2110.'],
            ['code' => '5020', 'name' => 'Platform & Payment Fees', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Deel and Grey fees. An expense, never a reduction of 4010.'],

            // ---------------------------------------------------------------
            // Operating expenses
            // ---------------------------------------------------------------
            ['code' => '6010', 'name' => 'Salaries & Wages', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Gross pay; PAYE and pension are liabilities, not reductions.'],
            ['code' => '6020', 'name' => 'Employer Pension Contribution', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6030', 'name' => 'Staff Welfare', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6100', 'name' => 'Rent', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6110', 'name' => 'Utilities', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6120', 'name' => 'Internet & Communications', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6130', 'name' => 'Software Subscriptions', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6140', 'name' => 'Professional Fees', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Accountancy and legal — a common WHT-suffered category.'],
            ['code' => '6150', 'name' => 'Bank Charges', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Bank fees proper, as opposed to 5020 platform fees.'],
            ['code' => '6160', 'name' => 'Travel & Transport', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6170', 'name' => 'Marketing & Advertising', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6180', 'name' => 'Office Supplies', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6190', 'name' => 'Repairs & Maintenance', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
            ['code' => '6200', 'name' => 'Depreciation Expense', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Non-cash — the indirect cash flow statement adds it back.'],
            ['code' => '6210', 'name' => 'Input VAT — Expensed', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Non-recoverable input VAT is a cost, not an asset (D5a).'],
            ['code' => '6900', 'name' => 'Other Operating Expenses', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],

            // ---------------------------------------------------------------
            // Other income and expenses
            // ---------------------------------------------------------------
            ['code' => '7100', 'name' => 'FX Gain', 'type' => $revenue, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Fixed by D7/D9 — ConvertCurrency posts its residual here by code. Realized gains, and Phase 5 revaluation gains.'],
            ['code' => '7200', 'name' => 'Interest Income', 'type' => $revenue, 'monetary' => false, 'cash_flow' => $investing, 'description' => 'T-bill accretion and bond coupons. Classified investing per the policy choice in chart-of-accounts.md § 7.5.'],
            ['code' => '7300', 'name' => 'Dividend Income', 'type' => $revenue, 'monetary' => false, 'cash_flow' => $investing],
            ['code' => '7400', 'name' => 'Gain on Disposal', 'type' => $revenue, 'monetary' => false, 'cash_flow' => $investing],

            ['code' => '8100', 'name' => 'FX Loss', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating, 'description' => 'Fixed by D7/D9 — the mirror of 7100.'],
            ['code' => '8200', 'name' => 'Interest Expense', 'type' => $expense, 'monetary' => false, 'cash_flow' => $financing],
            ['code' => '8400', 'name' => 'Loss on Disposal', 'type' => $expense, 'monetary' => false, 'cash_flow' => $investing],

            // ---------------------------------------------------------------
            // Income tax
            // ---------------------------------------------------------------
            ['code' => '9100', 'name' => 'Company Income Tax Expense', 'type' => $expense, 'monetary' => false, 'cash_flow' => $operating],
        ];
    }
}
