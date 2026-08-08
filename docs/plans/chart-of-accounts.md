# Ledgerly — Default Chart of Accounts (draft)

**Status:** draft, Phase 0. The seeder that loads this lands in Phase 1.
**Drafted:** 2026-08-08, against `ledgerly-development-plan.md` § 4 and requirements R1–R8.

This is the content of the `accounts` table at first boot. It is a draft in the sense that
the *numbers and names* want a review before the seeder is written — not in the sense that
columns are optional. Every account below carries `is_monetary` and
`cash_flow_classification`, because the plan is explicit that retrofitting either means
re-classifying every account by hand.

---

## 1. Numbering scheme

`code` is unique, sortable, and drives report ordering. Blocks are wide enough that a new
account almost never forces a renumber.

| Range | Contents | `type` |
|---|---|---|
| 1000–1999 | Assets | `Asset` |
| 2000–2999 | Liabilities | `Liability` |
| 3000–3999 | Equity | `Equity` |
| 4000–4999 | Operating revenue | `Revenue` |
| 5000–5999 | Direct costs | `Expense` |
| 6000–6999 | Operating expenses | `Expense` |
| 7000–7999 | Other income (incl. `7100 FX Gain`) | `Revenue` |
| 8000–8999 | Other expenses (incl. `8100 FX Loss`) | `Expense` |
| 9000–9999 | Income tax | `Expense` |

`7100 FX Gain` and `8100 FX Loss` are fixed by D7/D9 — `ConvertCurrency` posts its residual
to them by code, so those two numbers are not free to move.

## 2. What the columns mean here

- **`currency_code`** — set only where the account is *denominated* in one currency, which
  in practice means the bank and platform balances. Null everywhere else, meaning "accepts
  any currency". It constrains entry; it does not replace the per-line `currency_code`.
- **`is_monetary`** — true when the balance is a claim to, or obligation for, a fixed number
  of currency units. This is the flag Phase 5 revaluation reads, so it is true for cash,
  receivables and payables, and false for prepayments, fixed assets, equity, and everything
  in the income statement. Getting it wrong on a USD balance misstates the year-end balance
  sheet; getting it wrong on a non-monetary one invents FX movement that never happened.
- **`is_cash_equivalent`** — true only for the seven balances in § 3.1. These are what the
  cash flow statement reconciles *to*, so their own `cash_flow_classification` is null.
- **`cash_flow_classification`** — `operating` / `investing` / `financing`, per § 6.
- **`normal_balance` is not a column.** It is a function of `type`.

---

## 3. Assets (1000–1999)

### 3.1 Cash and bank — one GL account per financial account (R2)

Each of these maps 1:1 to a `financial_accounts` row via `gl_account_id`. All are
`is_monetary = true`, `is_cash_equivalent = true`, `cash_flow_classification = null`.

| Code | Name | Currency | Parent |
|---|---|---|---|
| 1010 | Cash and Bank | — | 1000 |
| 1011 | Zenith Bank — NGN | NGN | 1010 |
| 1012 | Zenith Bank — USD | USD | 1010 |
| 1013 | Grey — NGN | NGN | 1010 |
| 1014 | Grey — USD | USD | 1010 |
| 1015 | Deel — USD | USD | 1010 |
| 1016 | Moniepoint — NGN | NGN | 1010 |
| 1017 | Kuda — NGN | NGN | 1010 |
| 1018 | *(reserved — see § 7, open question 1)* | | 1010 |

Grey and Deel are not banks, but both balances are withdrawable on demand and so are cash
equivalents for IAS 7 purposes. Their *fees* are an expense (5020), never a reduction of
revenue. A Grey USD→NGN move between 1014 and 1013 is the `ConvertCurrency` case in D9, not
a transfer.

### 3.2 Other current assets

| Code | Name | Cur. | Monetary | Cash-flow | Parent | Notes |
|---|---|---|---|---|---|---|
| 1000 | Current Assets | — | false | — | — | Header |
| 1100 | Accounts Receivable | — | **true** | operating | 1000 | AR control (D4). Sub-ledger is `contact_id` on the line, not a GL account per partner |
| 1200 | Prepayments | — | false | operating | 1000 | A right to goods/services, not to currency — hence non-monetary |
| 1300 | WHT Receivable | — | **true** | operating | 1000 | D5: tax withheld on receipts is an asset creditable against CIT, never a cost |
| 1310 | Input VAT — Recoverable | — | **true** | operating | 1000 | Only where recoverable; the rest is expensed to 6210 (see § 7, open question 3) |
| 1400 | Short-term Investments | — | false | investing | 1000 | Header |
| 1410 | Treasury Bills — at amortised cost | — | **true** | investing | 1400 | Single carrying value; discount accretes into this account (§ 7, note 4) |
| 1420 | Accrued Interest Receivable | — | **true** | investing | 1400 | Coupon earned but not received |

### 3.3 Non-current assets

| Code | Name | Cur. | Monetary | Cash-flow | Parent | Notes |
|---|---|---|---|---|---|---|
| 1500 | Non-current Investments | — | false | investing | — | Header |
| 1510 | Bonds — at amortised cost | — | **true** | investing | 1500 | R4 |
| 1520 | Equity Investments — at cost | — | false | investing | 1500 | Shares are not a claim to fixed currency, so non-monetary. Carried at cost per D16 |
| 1600 | Property, Plant & Equipment | — | false | investing | — | Header |
| 1610 | Computer & Office Equipment — cost | — | false | investing | 1600 | R8's Studio Display lands here |
| 1615 | Accumulated Depreciation — Computer & Office Equipment | — | false | investing | 1600 | Contra-asset (credit balance) |
| 1620 | Land — cost | — | false | investing | 1600 | **Not depreciated** — keep separate from buildings |
| 1630 | Buildings — cost | — | false | investing | 1600 | R4 real estate |
| 1635 | Accumulated Depreciation — Buildings | — | false | investing | 1600 | Contra-asset |

---

## 4. Liabilities (2000–2999)

All are `is_monetary = true` unless noted — an obligation to pay a fixed sum is the
definition of a monetary item.

| Code | Name | Cur. | Monetary | Cash-flow | Parent | Notes |
|---|---|---|---|---|---|---|
| 2000 | Current Liabilities | — | false | — | — | Header |
| 2010 | Accounts Payable | — | true | operating | 2000 | AP control (D4) |
| 2020 | Due to Partners | — | true | operating | 2000 | **R8.** UpSub's $3,600 hardware liability sits here and is recovered at $600/month, Jul–Dec 2026. USD-denominated in practice but left currency-agnostic so other partners can share the control account; `is_monetary` is what drives its year-end revaluation |
| 2100 | Output VAT | — | true | operating | 2000 | D5 |
| 2110 | WHT Payable | — | true | operating | 2000 | Withheld from contractors, owed to FIRS |
| 2120 | PAYE Payable | — | true | operating | 2000 | R5 |
| 2130 | Pension Payable | — | true | operating | 2000 | R5 — employee and employer portions both settle here |
| 2140 | NHF Payable | — | true | operating | 2000 | R5 |
| 2200 | Accrued Expenses | — | true | operating | 2000 | |
| 2210 | Salaries & Wages Payable | — | true | operating | 2000 | Net pay owed between run and payment |
| 2300 | Company Income Tax Payable | — | true | operating | 2000 | |

---

## 5. Equity (3000–3999)

All non-monetary, all `financing`.

| Code | Name | Notes |
|---|---|---|
| 3000 | Share Capital | |
| 3100 | Retained Earnings | **D2.** Year-end close posts revenue and expense balances here as a real journal entry |
| 3200 | Opening Balance Equity | **D2.** The contra side of Phase 2's opening balances. Should be zero once opening balances are complete — a non-zero balance is a live "you are not finished" signal, which is why it stays a real account rather than being netted into 3100 |
| 3300 | Dividends & Distributions | Closed into 3100 at year end |

**There is no "Current Year Earnings" account.** It would be a stored statement figure, which
invariant 3 forbids; the figure is derived from `journal_lines` between the period bounds.

---

## 6. Income statement (4000–9999)

### 6.1 Revenue (4000–4999) — all `operating`, all non-monetary

| Code | Name | Notes |
|---|---|---|
| 4000 | Operating Revenue | Header |
| 4010 | Service Revenue | R6. **Gross**, before platform fees and before any partner deduction. Which partner is `contact_id` on the line, not a separate GL account |
| 4020 | Rental Income | R4 real estate |
| 4090 | Other Operating Income | |

R8's netting works here: the UpSub receipt books gross revenue to 4010, the $600 deduction
debits 2020, and only the difference hits cash. Booking net cash to 4010 would understate
revenue by $600/month and never record the asset.

### 6.2 Direct costs (5000–5999) — `operating`

| Code | Name | Notes |
|---|---|---|
| 5010 | Contractor Costs | R5 contractor payments — distinct from payroll, and the WHT side posts to 2110 |
| 5020 | Platform & Payment Fees | Deel and Grey fees. An expense, never a reduction of 4010 |

### 6.3 Operating expenses (6000–6999) — `operating`

| Code | Name | Notes |
|---|---|---|
| 6010 | Salaries & Wages | R5 — **gross** pay; PAYE and pension are liabilities, not reductions |
| 6020 | Employer Pension Contribution | The employer portion only |
| 6030 | Staff Welfare | |
| 6100 | Rent | |
| 6110 | Utilities | |
| 6120 | Internet & Communications | |
| 6130 | Software Subscriptions | |
| 6140 | Professional Fees | Accountancy, legal — a common WHT-suffered category |
| 6150 | Bank Charges | Bank fees proper, as opposed to 5020 platform fees |
| 6160 | Travel & Transport | |
| 6170 | Marketing & Advertising | |
| 6180 | Office Supplies | |
| 6190 | Repairs & Maintenance | |
| 6200 | Depreciation Expense | D13. Non-cash — the cash flow statement adds it back |
| 6210 | Input VAT — Expensed | D5a. Non-recoverable input VAT is a cost, not an asset |
| 6900 | Other Operating Expenses | |

### 6.4 Other income (7000–7999) and other expenses (8000–8999)

| Code | Name | Type | Cash-flow | Notes |
|---|---|---|---|---|
| 7100 | **FX Gain** | Revenue | operating | **Fixed by D7/D9.** Realized gains, and Phase 5 revaluation gains |
| 7200 | Interest Income | Revenue | investing | T-bill accretion and bond coupons (R4) |
| 7300 | Dividend Income | Revenue | investing | |
| 7400 | Gain on Disposal | Revenue | investing | Assets and investments. Classified `investing` so the indirect statement reverses it out of operating and shows the proceeds under investing |
| 8100 | **FX Loss** | Expense | operating | **Fixed by D7/D9** |
| 8200 | Interest Expense | Expense | financing | |
| 8400 | Loss on Disposal | Expense | investing | Mirror of 7400 |

### 6.5 Income tax (9000–9999)

| Code | Name | Type | Cash-flow |
|---|---|---|---|
| 9100 | Company Income Tax Expense | Expense | operating |

---

## 7. Open questions and policy notes

These want an answer before the Phase 1 seeder is written. None of them block starting it.

1. **R2 says "8 account-currency pairs" but enumerates seven.** Zenith (2) + Grey (2) +
   Deel (1) + Moniepoint (1) + Kuda (1) = 7. Either a Deel NGN or Moniepoint USD balance is
   missing from the list, or the count is off by one. Code **1018 is left unassigned** until
   this is settled — the seeder should not invent an account, and it should not silently
   ship seven where the requirement says eight.

2. **Header accounts have no `is_postable` column.** § 4 of the plan gives `accounts` a
   `parent_id` but nothing that stops someone posting directly to `1000 Current Assets`,
   which would double-count it against its children in any report that sums a subtree.
   Cheapest fix, and the recommendation: derive it — *an account with children cannot be
   posted to* — and enforce it in `PostEntry` rather than adding a column that can drift out
   of step with the tree. Decide in Phase 1, before the chart is seeded.

3. **D5a — input VAT recoverability (needs your accountant).** The split between 1310 and
   6210 above assumes the Nigerian default: input VAT on overheads, services and general
   administration is *not* creditable and is expensed. The chart supports either answer; the
   default the seeder ships should be confirmed before Phase 6.

4. **T-bills use one carrying account, not a face-value/discount pair.** 1410 is debited at
   cost, accretion debits 1410 and credits 7200 monthly, and maturity clears 1410 to cash.
   This keeps the ledger side simple; `investments.face_value_minor` and `matures_on` carry
   the instrument detail, and the accretion schedule is computed there. Straight-line
   accretion for tenors of a year or less, per § 3.4 of the plan.

5. **Interest and dividends are classified `investing`, not `operating`.** IAS 7 permits
   either for a non-financial entity. Investing is the more informative choice here, since
   R4's investment income is genuinely separate from the service business. This is a policy
   choice, and it must be applied consistently once the first cash flow statement is filed.

6. **Contra-asset accounts (1615, 1635) are `Asset` type with a natural credit balance.**
   They are not liabilities. Reports that group by `type` must present them as deductions
   within their parent block rather than sorting them by sign.
