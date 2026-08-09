# Ledgerly — Development Plan

**A double-entry financial management app for Cibsmart Services Ltd**

- **Backend:** Laravel 13 (PHP 8.5)
- **Frontend:** Inertia.js v3 + React 19 + Tailwind v4
- **Database:** PostgreSQL (DBngin, `127.0.0.1:5432`, database `ledgerly`)
- **Auth:** Laravel Fortify (React starter kit), with passkeys and 2FA already wired
- **Users:** Owner + small internal team
- **Serving:** Herd at `http://ledgerly.test`

---

## 0. Where the Repo Actually Is

Read this before Phase 0 — the scaffold is further along than a bare Laravel install, and in different places than a default one.

**Already installed and working:** Fortify auth (login, registration, password reset, 2FA, passkeys), Inertia v3 + React 19, Tailwind v4 with a Radix/shadcn component set, Wayfinder (typed route functions for the frontend), Scout with the `database` driver, Pest 4, Pint, Larastan, Nightwatch (no token locally, so nothing is ingested), and a GitHub Actions workflow running `composer ci:check` (ESLint, Prettier, `tsc`, Pint, PHPStan, Pest).

**Already built (Phase 0, 2026-08-08):** PostgreSQL in CI plus a full second suite run against it, `spatie/laravel-activitylog` behind `App\Actions\Audit\RecordAudit`, the grouped sidebar shell, and the default chart of accounts drafted in `chart-of-accounts.md`.

**Already built (Phase 2 so far, 2026-08-08):** opening balances and exchange rate management — see `backlog.md` § 4a, which records two engine divergences the PostgreSQL run caught that would have shipped green on sqlite.

**Already built (Phase 1, 2026-08-08):** the whole ledger core — migrations with the deferred balance trigger, `PostEntry` / `ReverseEntry` / `ClosePeriod` / `ReopenPeriod` / `CreatePeriod`, the Role/Permission enums and four policies, the seeded 70-account chart, the 50-entry golden fixture with hand-verified figures, and the Chart of Accounts screen. 240 tests green on both engines. **Read `backlog.md` § 3 for the known gaps** — notably that there is still no screen for periods, exchange rates, or user roles.

**Already built (Foundation hardening, 2026-07-27):** `App\Support\Money` (currency-aware, throws on cross-currency arithmetic), `App\Support\Currency`, `MoneyCast`, `config/money.php`, `Model::preventLazyLoading` + `Relation::enforceMorphMap` in `AppServiceProvider`, the audit-completeness architecture test, and the `expect()->toBalance()` custom expectation.

**Not installed, and not to be installed without approval:** `spatie/laravel-permission`, `mpdf/mpdf`, `openspout/openspout`, `recharts`.

**Do not install Breeze.** Fortify is already in place and the starter kit's auth pages are built against it.

**Database state:** the ledger exists. `accounts`, `periods`, `exchange_rates`, `journal_entries`, `journal_lines` and `journal_entry_sequences` are migrated, with the deferred balance trigger and the CHECK constraints on PostgreSQL. `php artisan migrate:fresh --seed` gives you 70 seeded accounts and 24 monthly periods; `GoldenFixtureSeeder` adds the 50-entry fixture company on top.

---

## 1. What This App Is Actually For

This is not a generic accounting product. It runs the books of one Nigerian company, and every scope decision below is answerable by pointing at something the business actually does. **When a design choice is ambiguous, resolve it in favour of what this list needs.**

| # | Requirement | What it forces into the design |
|---|---|---|
| R1 | Manage Cibsmart Services Ltd's finances | Single entity, NGN functional currency. No multi-tenancy. |
| R2 | 8 account-currency pairs across 5 institutions — Zenith (USD/NGN), Grey (USD/NGN), Deel (USD), Moniepoint (NGN), Kuda (NGN) | `financial_accounts` distinct from GL accounts; own-account FX conversion (Grey USD→NGN); high transfer volume |
| R3 | Download statements, upload, reconcile | Import is the **primary data-entry path**, not a cleanup tool. Staging table, dedupe, per-institution parsers |
| R4 | Investments: T-bills, bonds, stocks, real estate | Full instrument accounting — face value, maturity, discount accretion, coupon schedules, per-lot cost basis |
| R5 | Monthly salaries — employees *and* contractors | Payroll runs with PAYE/pension for staff; flat payments with optional WHT for contractors |
| R6 | Partner receipts in USD (UpSub) and NGN | Receivables, invoice-to-settlement realized FX, platform fees on gross-vs-net |
| R7 | Expenses with invoice/receipt on file | Attachments as a first-class, promptable requirement — plus an "unreceipted expenses" report |
| R8 | Partners buy hardware, recover it in instalments (UpSub: $3,600 as 6 × $600, Jul–Dec 2026) | Payables, net settlement against gross revenue, a fixed asset, and a USD monetary balance crossing year-end |

---

## 2. Architecture Principles

These hold across every phase below:

1. **Single source of truth.** The income statement, balance sheet, and cash flow statement are never separately maintained — they are all *derived views* computed from the same journal entries. If two reports can disagree, the design is wrong.
   *Clarification:* a query-level **cache** rebuilt from `journal_lines` and invalidated on posting is permitted. A **column** holding a balance or statement figure as its own source of truth is not. The test: if the cache were dropped, could every number be recomputed exactly? If no, it's a second source of truth.
2. **Immutable journal entries.** Once posted, an entry is never edited or deleted. Corrections happen via a reversing entry.
3. **Debits always equal credits, in the functional currency.** Enforced in `App\Actions\Ledger\PostEntry` before anything touches the database, and by a deferred `SUM(functional_amount_minor) = 0` constraint in PostgreSQL. `CHECK` constraints guarantee line sanity independently, so a job, command or console script bypassing the action still cannot write a nonsense row.
4. **Money is never a float, and never currency-less.** `bigInteger` minor units suffixed `_minor`, cast to `App\Support\Money`. No `NUMERIC` money columns, no JS arithmetic.
5. **Everything is attributable.** Every entry, posting, approval and reversal records who did it and when.
6. **Closed periods are closed.** Corrections go into a later open period as reversing entries.
7. **Every subsidiary module posts through the ledger.** Payroll, investments, fixed assets, invoices and imports do not write `journal_lines` — they call `PostEntry` and tag provenance through `source_type`/`source_id`. A module that can produce a number the general ledger doesn't know about is a second source of truth wearing a costume.

---

## 3. Decisions

### 3.1 Resolved

| # | Decision | Resolution |
|---|---|---|
| D1 | **Line shape** | ✅ **Single signed `amount_minor`**, debit positive, `CHECK (amount_minor <> 0)`. Makes the balance assertion `SUM(functional_amount_minor) = 0` — checkable by a deferred database constraint rather than application code alone. Dual amounts (D3) would otherwise mean four amount columns per line and four places a translation bug can hide. Reports render debit/credit by sign. |
| D2 | **Year-end close** | ✅ **Explicit closing entries** (2026-07-28). At year end, revenue and expense balances are closed into Retained Earnings by a real journal entry. This does *not* violate principle 1 — a closing entry is ledger data, not a stored statement figure. It keeps the balance sheet query simple across fiscal years and is what an accountant expects at CIT filing time. Seed **Retained Earnings** and **Opening Balance Equity** in the default chart. |
| D3 | **Currency** | ✅ **Multi-currency, single functional currency.** Books in NGN (`config/money.php`); transactions in any configured currency. Every line carries transaction amount + rate used + translated functional amount. **Only functional amounts balance.** |
| D4 | **AR/AP scope** | ✅ **Track receivables and payables** (2026-07-28). Forced by R8: UpSub's $600/month deduction is arithmetically inexpressible without a payable, and recording only net cash understates revenue by $600/month while never booking the $3,600 asset. R6 needs the same for invoice-to-settlement FX. |
| D5 | **Tax (VAT/WHT)** | ✅ **In scope, but optional per transaction** (2026-07-28). The company is VAT-registered and WHT applies in both directions, **but current partners pay in full with no VAT charged and no WHT withheld.** Therefore: tax lines default to *none*, are never mandatory, and are chosen per transaction (defaulting from the contact, always overridable). See § 3.3 for the model and the two open sub-questions. |
| D6 | **FX rate source** | ✅ **`exchange_rates` table with effective dating, rate snapshotted onto each line.** A posted line never re-reads the table, so correcting a historical rate changes future postings and nothing about the past. No API feed in the MVP. **Amended (D8):** the table is a *fallback*, not the authority — see below. |
| D7 | **FX gain/loss scope** | ✅ **Realized FX from Phase 2; period-end revaluation promoted into Phase 5** (2026-07-28, was post-MVP). R8's "Due to UpSub" is an open USD monetary liability spanning Jul–Dec 2026 and crossing the fiscal year-end. Leaving it unrevalued under NGN/USD volatility misstates the balance sheet by a material amount. `is_monetary` must be populated correctly on every account from Phase 1. |
| D11 | **Investment depth** | ✅ **Full instrument accounting** (2026-07-28). Not a position-and-valuation shortcut. See § 3.4. |
| D14 | **Statement formats** | ✅ **CSV/Excel from all five institutions** (2026-07-28). No PDF statement parsing is required, which removes the single most fragile part of R3. Import is a per-institution column-mapping layer over a structured reader. |

### 3.2 New decisions arising from § 1

| # | Decision | Resolution |
|---|---|---|
| D8 | **Transaction-rate override** | The `exchange_rates` lookup is a **default, not the authority.** When you convert USD→NGN inside Grey, the rate is the specific rate that conversion executed at — it must be an explicit per-line input that overrides the table. `PostEntry` accepts an optional rate per line; absent one, it resolves from `exchange_rates` by `entry_date`. CLAUDE.md currently reads as though the lookup is the only path; that wording is corrected. |
| D9 | **Own-account FX conversion** | A conversion between two of your own accounts is a **two-currency entry that does not net to zero in functional terms without an explicit FX gain/loss line.** This is the most frequent FX event in the business (R2) and was entirely unmodelled. A dedicated `ConvertCurrency` action computes the residual and posts it to `7100 FX Gain` / `8100 FX Loss`. This is the sharpest FX test case in the suite. |
| D10 | **Transfers** | First-class, but *not* a new table — a transfer is one journal entry containing both legs, tagged `source_type = transfer`. This makes the double-count problem solve itself: because the entry already holds lines for both accounts, importing *either* institution's statement matches an existing posted line rather than creating a second entry. Spell this out in the matcher, or the first Zenith→Moniepoint transfer imported from both sides will be booked twice. |
| D12 | **Stock cost basis** | **Weighted average cost.** Permitted under IFRS (LIFO is not), simpler than FIFO lot tracking, and matches how Nigerian brokers report. `investment_lots` still records each purchase for audit; disposals compute basis from the running weighted average. |
| D13 | **Fixed assets & depreciation** | **A real module, currently absent from the plan entirely.** R8's Studio Display and R4's real estate are both depreciable fixed assets. `fixed_assets` + a monthly depreciation run posting through `PostEntry`. Straight-line only in v1. |
| D15 | **Payroll tax rates** | **Configurable data with effective dating, never hardcoded.** PAYE bands, pension rates (8% employee / 10% employer), NHF and any other statutory rate live in a `tax_bands`/`statutory_rates` table keyed by effective date. ⚠️ **The band set in force for 2026 must be confirmed against the Nigeria Tax Act 2025 before the payroll module is built — do not take rates from this document or from model memory.** Effective dating means a mid-year change is a data edit, not a migration. |
| D17 | **Audit storage** | ✅ **`spatie/laravel-activitylog`** (2026-08-08). Chosen over a hand-rolled table for application-wide coverage: the ledger's own events are domain events written by hand either way, but contacts, accounts and settings get model-level logging for free. Wrapped in `App\Actions\Audit\RecordAudit`, which throws when logging is disabled — the package returns null and writes nothing, which is not a survivable failure mode for attribution. **v5 has no `LogBatch`**, so grouping a payroll run's postings needs our own key. |
| D16 | **Unrealized investment gains** | **Disclosed, not posted, in v1.** Stocks and real estate are carried at cost; `investment_valuations` feeds reporting and an unrealized-gain figure, but posts nothing to the ledger. Fair-value accounting can be added later; historical cost keeps principle 1 clean and avoids revaluation reserves in a first release. |

### 3.3 The tax model (D5)

The controlling constraint is that **tax must be present but almost always off.** A design that makes VAT or WHT a required field will be wrong on nearly every transaction this business records today, and users route around required fields by entering garbage.

- `tax_rates` — `id, name, kind, rate, gl_account_id, effective_from, effective_to, is_active`. `kind` is a backed enum: `VatOutput`, `VatInput`, `WhtReceivable`, `WhtPayable`.
- Tax is applied **per transaction line**, defaulting from the contact and always overridable. The default default is **none**.
- `contacts` gain `default_vat_treatment` and `default_wht_rate_id`, so UpSub is configured once as "no VAT, no WHT" and never asks again.
- **Zero-rated is not the same as none.** Export of services to a foreign partner is a VAT *treatment*, not an absence of one, and it belongs on the VAT return. Model `Standard` / `ZeroRated` / `Exempt` / `OutOfScope` distinctly even though the posted amount is nil for the last three.
- **WHT suffered on receipts is an asset, not a cost.** When a partner pays net of 5–10%, `Dr Cash / Dr WHT Receivable / Cr AR`. Booking only the net cash silently writes off money creditable against CIT.

Two sub-questions remain open and are flagged rather than guessed:

- **D5a — input VAT recoverability.** Nigerian VAT restricts input-VAT credit far more tightly than most jurisdictions; input VAT on overheads, services and general administration is generally *not* creditable and is expensed instead. The model should therefore default to **input VAT expensed, recoverable only when explicitly flagged** — but confirm the current rule with your accountant before the tax layer is built.
- **D5b — VAT filing.** Whether the app produces a VAT return or just the underlying figures for whoever files it.

### 3.4 The investment model (D11)

The previous plan's `investments: id, name, type, amount_invested, acquired_at, currency` cannot express a single one of the four instrument types. Specifically:

- **Treasury bills are discount instruments.** You pay ₦9.2m for ₦10m face at 364 days. The ₦800k is interest earned across the tenor, not a gain at maturity. Without `face_value_minor`, `matures_on` and discount accretion, income lands entirely in the maturity month and every intervening income statement is wrong.
- **Bonds** pay periodic coupons and need a coupon schedule plus accrued interest at period end.
- **Stocks** need `quantity` and cost basis; `amount_invested` alone cannot compute realized gain on a partial disposal.
- **Real estate is not this table.** It is a fixed asset (D13) with depreciation and rental income — filing it beside a 364-day T-bill is a category error.

Three tables:

- `investments` — `id, name, instrument_type, contact_id (issuer/broker), currency_code, gl_account_id, opened_on, matures_on, face_value_minor, rate, coupon_frequency, day_count, status`
- `investment_transactions` — `id, investment_id, type (Purchase|Accrual|CouponReceipt|DividendReceipt|Sale|Maturity), trade_date, settlement_date, quantity, unit_price_minor, gross_minor, fees_minor` — every row posts through `PostEntry` and is found back through `source_type`/`source_id`
- `investment_valuations` — `id, investment_id, value_minor, valued_at, recorded_by` — reporting only, per D16

**Accretion method:** straight-line over the tenor for instruments of one year or less (materially identical to effective-interest at these tenors, and far easier to verify by hand); effective-interest available for longer bonds. Confirm before building.

---

## 4. Core Data Model

Column lists are the *minimum*.

### `accounts` — chart of accounts

`id, code, name, type, parent_id, currency_code, is_monetary, cash_flow_classification, is_cash_equivalent, is_active, description, timestamps`

- **`code`** — numbered (1000–1999 assets, 2000–2999 liabilities, …). Drives report ordering; unique, sortable, seeded.
- **`type`** — backed enum: `Asset`, `Liability`, `Equity`, `Revenue`, `Expense`.
- **`normal_balance` is deliberately NOT a column.** It is a pure function of `type` — storing it creates two sources of truth that can drift.
- **`currency_code`** (nullable) — the currency an account is *denominated* in (a USD bank account). Null accepts any currency. Constrains entry; does not replace the per-line `currency_code`.
- **`is_monetary`** — whether the balance is a claim to a fixed number of currency units. Now load-bearing, not speculative: D7 promotes revaluation into Phase 5.
- **`cash_flow_classification`** (`operating`/`investing`/`financing`, nullable) and **`is_cash_equivalent`** — the indirect cash flow statement cannot be built without them, and adding them later means re-classifying every account by hand.
- **Seeded chart must include:** Retained Earnings, Opening Balance Equity (D2), `7100 FX Gain` / `8100 FX Loss` (D7/D9), AR and AP control accounts (D4), WHT Receivable / WHT Payable / Input VAT / Output VAT (D5), PAYE Payable and Pension Payable (R5), one asset account per financial account in R2, and Due-to-Partner (R8).
- **Accounts are archivable, never deletable** once any line references them.

### `financial_accounts` — the real-world bank/platform accounts (R2, R3)

`id, institution, name, currency_code, gl_account_id, external_identifier, import_profile, statement_last_imported_on, opening_balance_minor, opening_balance_on, is_active, timestamps`

- **Distinct from `accounts` on purpose.** Zenith is one institution with two currency sub-accounts, each mapping to its own GL account. Statement metadata, import column mappings and last-import watermarks belong here, not on the chart of accounts.
- `import_profile` names the per-institution parser/column map (D14). Five profiles at launch: Zenith, Grey, Deel, Moniepoint, Kuda.
- Grey and Deel are **not banks**; a Grey USD→NGN move is an FX conversion (D9), and Deel receipts arrive net of platform fees that must be booked as an expense rather than shrinking revenue.

### `journal_entries` — the transaction header

`id, entry_number, entry_date, period_id, memo, reference, status, created_by, posted_by, posted_at, reverses_journal_entry_id, source_type / source_id, timestamps`

- **`entry_number`** — human-readable (`JE-2026-0142`). Allocation must be race-safe: a database sequence or atomic counter, never `MAX(...) + 1`.
- **`entry_date` determines the period**, not `posted_at` and not `created_at`.
- **`period_id`** resolved from `entry_date` at post time and stored, so "is this period closed?" is an indexed check.
- **`reverses_journal_entry_id`** — *this entry reverses that one*. **Unique index**: an entry may be reversed at most once.
- **`status`** — `draft`, `posted`. No `reversed` status; that is derived from the existence of a reversing entry.
- **`source_type` / `source_id`** — polymorphic provenance. Principle 7 means this is populated by every subsidiary module: payroll runs, depreciation runs, investment transactions, invoices, imports, transfers.

### `journal_lines` — the debit/credit lines

`id, journal_entry_id, account_id, currency_code, amount_minor, exchange_rate, functional_amount_minor, memo, contact_id (nullable), tax_rate_id (nullable), timestamps`

- **All amounts are `bigInteger` minor units.** Integers behave identically on SQLite and PostgreSQL, so the `:memory:` suite verifies the invariant on production storage semantics.
- **`amount_minor`** — signed, debit positive, in the **transaction** currency. `CHECK (amount_minor <> 0)`.
- **`exchange_rate`** — `NUMERIC(20,10)`, snapshotted at post time, never re-derived. 1 when already functional. Sourced from the explicit per-line rate when given, else `exchange_rates` (D8).
- **`functional_amount_minor`** — signed, functional currency. The only column a trial balance sums.
- **`SUM(functional_amount_minor) = 0` per entry**, enforced by a **deferred** database constraint.
- **`tax_rate_id`** (nullable, D5) — nullable is the norm, not the exception.
- **Reconciliation columns (`cleared_at`, `reconciliation_id`)** now arrive in Phase 4 rather than as a stretch goal.

### `bank_transactions` — imported statement rows (R3)

`id, financial_account_id, posted_on, value_date, description, reference, amount_minor, currency_code, running_balance_minor, dedupe_hash, status, matched_journal_line_id, imported_by, import_batch_id, timestamps`

- **A staging table, deliberately not ledger data.** An imported row is a claim about the outside world; it becomes ledger data only when matched to an existing line or used to create an entry. The previous plan's `cleared_at` + `reconciliation_id` approach supports matching but has nowhere to put an imported row that has no entry yet — which is most of them.
- **`dedupe_hash`** — unique per financial account, derived from date + amount + reference/description. **You will re-upload overlapping date ranges**; without this, every overlap double-posts.
- `status`: `unmatched`, `matched`, `posted`, `ignored`.

### `reconciliations` — R3

`id, financial_account_id, statement_start_on, statement_end_on, statement_closing_balance_minor, reconciled_balance_minor, status, reconciled_by, reconciled_at, timestamps`

- Reconciliation is only meaningful if it asserts **statement closing balance == GL balance at that date**, including uncleared items. A screen that just ticks rows off proves nothing.

### `exchange_rates` — FX rate history (D6)

`id, base_code, quote_code, rate, effective_on, source, created_by, timestamps`

- **`UNIQUE (base_code, quote_code, effective_on)`.** `rate` is `NUMERIC(20,10)`.
- A *lookup and a default* (D8), not the authority — a posted line never reads it again.

### `periods` — fiscal periods

`id, fiscal_year, name, start_date, end_date, closed_at, closed_by, timestamps`

- Non-overlapping, contiguous. Closing and posting race — take a row lock on the `periods` row inside both transactions.

### `contacts` — partners, vendors, customers, employees' counterparties

`id, name, is_vendor, is_customer, default_vat_treatment, default_wht_rate_id, email, phone, notes, is_active, timestamps`

- Two booleans, not one `type` column — a party is routinely both.
- Tax defaults (D5) live here so UpSub is configured once.

### `attachments` — polymorphic (R7)

`id, attachable_type, attachable_id, path, original_name, mime_type, size, uploaded_by, timestamps`

- Attaching a receipt to a posted entry is **allowed** — it is not ledger data and changes no number. Log attach and detach.

### `fixed_assets` — D13

`id, name, category, contact_id, acquired_on, cost_minor, currency_code, useful_life_months, residual_minor, depreciation_method, gl_asset_account_id, gl_accumulated_depreciation_account_id, disposed_on, disposal_proceeds_minor, status, timestamps`

- Monthly depreciation run posts through `PostEntry`, tagged via `source_type`.

### `employees` / `payroll_runs` / `payroll_lines` — R5, D15

- `employees` — `id, name, employment_type (Employee|Contractor), contact_id, gross_pay_minor, currency_code, pension_applicable, default_wht_rate_id, started_on, ended_on`
- `payroll_runs` — `id, period_month, status, posted_journal_entry_id, run_by, timestamps`
- `payroll_lines` — per employee: gross, PAYE, employee pension, employer pension, NHF, net pay, plus WHT for contractors
- Statutory rates come from an effective-dated table (D15), never constants in code.

### `roles`, `activity_log`

- A simple `role` enum on `users` (`admin`/`bookkeeper`/`viewer`) plus policies is enough for the whole build.
- If `spatie/laravel-activitylog` is declined, principle 5 still holds — hand-rolled `activity_log` written by the actions.

---

## 5. Phases

**The order changed.** The previous plan put bank reconciliation in Phase 6 as "optional/stretch" and investments in a one-week Phase 4. R3 makes import the main way transactions get into the ledger, and R4 makes investments a multi-week module. Reconciliation moves up; investments move out of the MVP but get honest sizing.

**Nothing is blocked on a later phase.** From the end of Phase 2 the manual journal entry form can express *anything* — the UpSub deduction, a salary run, a T-bill purchase. Later phases automate what you can already record by hand. So the live Jul–Dec 2026 UpSub schedule and the monthly payroll are recordable well before their dedicated modules exist.

### Phase 0 — Foundation (1–2 days) — ✅ Complete 2026-08-08

- [x] **Fix CI:** PostgreSQL service container added to the `ci` job, plus a second `tests-pgsql` job running the whole suite against PostgreSQL. `DatabaseEngineTest` guards against that job silently degrading into a second sqlite run
- [x] **D17 — audit storage: `spatie/laravel-activitylog`** (2026-08-08). Approved and installed. Every write goes through `App\Actions\Audit\RecordAudit` rather than the `activity()` helper, so the guardrail test has a named entry point and a disabled logger throws instead of silently skipping attribution. Note v5 dropped `LogBatch`
- [x] Base layout: grouped sidebar (Ledger / Records / Reports) with unbuilt screens rendered as inert placeholders, navigation defined once in `resources/js/lib/navigation.ts`, starter-kit links removed
- [x] Draft the default chart-of-accounts content — `docs/plans/chart-of-accounts.md`. **Three items there need an answer before the Phase 1 seeder is written:** the R2 seven-vs-eight account discrepancy, whether header accounts get an `is_postable` column or derive it from having children, and D5a input-VAT recoverability

**Deliverable:** ✅ CI green against PostgreSQL, a shell layout to hang Phase 1 screens on.

### Phase 1 — Ledger Core & Chart of Accounts (2–2.5 weeks) — ✅ Complete 2026-08-08

- [x] Migrations: `accounts`, `journal_entries`, `journal_lines`, `periods`, `exchange_rates` — with the `CHECK` constraints and the deferred `SUM(functional_amount_minor) = 0` constraint **in the same migration as `journal_lines`**
- [x] Morph map aliases registered **before any rows exist**; `booted()` immutability guards shipped in the same commit as the models
- [x] **Indexes here, not in a hardening phase:** `journal_lines(account_id)`, `journal_lines(journal_entry_id)`, `journal_entries(entry_date)`, `journal_entries(period_id)`, partial index on posted entries
- [x] `PostEntry` / `ReverseEntry` — balance validation, period assignment from `entry_date`, closed-period rejection, attribution, race-safe `entry_number`, **per-line rate override with `exchange_rates` fallback (D8)**
- [x] Period locking and role/policy scaffolding — both here, because retrofitting gates across every controller is how a Viewer ends up able to post
- [x] Chart of Accounts UI: list, create, edit, archive, nesting, code-ordered
- [x] Seed the default chart, with `is_monetary` and `cash_flow_classification` populated on **every** account
- [x] **Golden fixture company** — ~50 entries across two fiscal periods with hand-verified trial balance, income statement and balance sheet committed alongside. Every later phase asserts against it. Highest-leverage artifact in the plan
- [x] Tests: unbalanced rejected; posted immutable; reversal correct and single-use; closed periods reject; ~~property-based~~ **randomised-input** — 25 balanced entries at random dates net to zero. Genuinely property-based testing needs a generator library, which is not installed and would need approval

### Phase 2 — Books Live (2–2.5 weeks) — in progress

- [x] **Opening balances** — first-class path posting through `PostEntry` against Opening Balance Equity. The first thing needed after seeding the chart. **The equity line is derived from the functional total**, so a run mixing currencies balances without the caller doing arithmetic
- [x] **Exchange rate management** — not in the original checklist, pulled forward because it was a Phase 1 gap: no USD transaction can be recorded by hand until rates can be entered. Create, correct and delete; `ExchangeRateResolver` extracted from `PostEntry` so anything computing a functional residual before posting resolves rates identically (D8)
- [ ] `financial_accounts` CRUD and their GL account mapping (R2)
- [ ] Simplified entry forms ("record an expense", "record income") generating correct double-entry behind the scenes, plus the raw manual journal form
- [ ] **Transfers (D10)** and **`ConvertCurrency` (D9)** — the two highest-volume entry types in this business, and the FX conversion is the sharpest test case in the suite
- [ ] Contacts management with tax defaults (D5)
- [ ] Attachments — receipts/invoices, local disk in dev, S3 in production (R7)
- [ ] Transaction list — filter by date, account, contact, amount; Scout `database` driver search
- [ ] Recurring transactions generating **drafts, never auto-posting**

**Deliverable:** every one of R1–R8 is *recordable*, by hand where no module exists yet.

### Phase 3 — Core Statements (2 weeks) — **MVP milestone**

- [ ] Trial Balance, Income Statement, Balance Sheet (any date range / as-of date)
- [ ] Year-end close mechanics per D2, including the Retained Earnings closing entry
- [ ] Every statement test asserts `expect($lines)->toBalance()` and is asserted against the Phase 1 golden fixture

**Deliverable:** **the app can produce correct books.** This is the MVP.

### Phase 4 — Statement Import & Reconciliation (2–3 weeks) — *promoted from "optional stretch"*

- [ ] `bank_transactions` + `reconciliations` migrations; `cleared_at` / `reconciliation_id` on `journal_lines`
- [ ] Pluggable per-institution import profiles — five at launch (D14). **Requires approval for `openspout/openspout`** for `.xlsx`; CSV alone needs nothing
- [ ] Idempotent import with `dedupe_hash` — re-uploading an overlapping range must be a no-op
- [ ] Matching: suggest existing lines, create entries from unmatched rows, **recognise transfer pairs so a Zenith→Moniepoint move imported from both sides is not booked twice (D10)**
- [ ] Reconciliation asserting statement closing balance == GL balance at date

**Deliverable:** R3 satisfied, and data entry stops being the bottleneck.

### Phase 5 — Receivables, Payables & FX (2 weeks)

- [ ] Invoices to partners and bills from vendors, with settlement matching (D4)
- [ ] **Realized FX** on settlement — the invoice-date vs. receipt-date rate difference (R6)
- [ ] **Period-end revaluation of open monetary balances (D7, promoted)** — without it the USD "Due to UpSub" balance misstates the year-end balance sheet
- [ ] **Partner deduction schedules (R8)** — a scheduled obligation recovered from future payments, netting against gross revenue rather than reducing it
- [ ] Platform fees (Deel/Grey) booked as expense, not as reduced revenue

### Phase 6 — Tax Layer (1.5 weeks)

- [ ] `tax_rates` with effective dating; optional per-line tax defaulting from contact, defaulting to none (D5)
- [ ] VAT treatments modelled distinctly: Standard / ZeroRated / Exempt / OutOfScope
- [ ] WHT receivable tracking with credit notes; WHT payable clearing on remittance
- [ ] **Resolve D5a (input VAT recoverability) and D5b (filing) with your accountant before starting**

### Phase 7 — Fixed Assets & Depreciation (1 week)

- [ ] `fixed_assets` CRUD, straight-line monthly depreciation run posting through `PostEntry` (D13)
- [ ] Disposal with gain/loss on disposal
- [ ] Acquisition-by-partner path feeding R8's hardware

### Phase 8 — Payroll (1.5–2 weeks)

- [ ] `employees`, `payroll_runs`, `payroll_lines`; employee and contractor paths (R5)
- [ ] Effective-dated statutory rate table (D15) — **confirm 2026 bands against the Nigeria Tax Act 2025 first**
- [ ] Payslips; PAYE/pension liabilities clearing on remittance

### Phase 9 — Investments (3–4 weeks, was "1 week")

- [ ] `investments`, `investment_transactions`, `investment_valuations` (§ 3.4)
- [ ] T-bill discount accretion; bond coupon schedules and accrued interest
- [ ] Weighted-average cost basis with realized gain on partial disposal (D12)
- [ ] Valuation history and unrealized gain — **disclosed, not posted (D16)**
- [ ] Every event posts through `PostEntry` (principle 7)

### Phase 10 — Cash Flow, Exports & Dashboard (1.5 weeks)

- [ ] Cash Flow Statement (indirect), on the `cash_flow_classification` metadata from Phase 1
- [ ] PDF/Excel export — **requires approval for `mpdf/mpdf`**; cheaper fallbacks are streamed CSV and the browser's print stylesheet
- [ ] Dashboard — **requires approval for `recharts`**

### Phase 11 — Hardening & Launch (1 week)

- [ ] Query performance against 100k+ lines — measure before caching, respect the cache-vs-column rule
- [ ] PostgreSQL backups with a **tested restore**. An untested restore is not a backup
- [ ] Staging environment and a migration rollback story — you cannot re-seed a production ledger
- [ ] Deployment target: Laravel Cloud is the lower-friction path and matches the local Herd setup
- [ ] Onboarding docs

---

## 6. Timeline, Honestly

| Milestone | Phases | Estimate (part-time) |
|---|---|---|
| **MVP — correct books, entered by hand** | 0–3 | **7–9 weeks** |
| **Usable daily — imports and reconciliation** | 4 | +2–3 weeks |
| **Complete against R1–R8** | 5–11 | +12–16 weeks |

Full coverage of all eight requirements is **roughly 5–7 months part-time**, not the original 5–7 weeks. The difference is not padding: investments alone went from one week to three or four once T-bill accretion and cost basis were taken seriously, and reconciliation, tax, payroll and fixed assets were absent from the original plan entirely.

**If you need to compress**, the honest lever is Phase 9 (investments). Recording T-bill purchases and maturities as manual journal entries is tedious but correct, and defers 3–4 weeks. The levers that are *not* available are Phases 3, 4 and 5 — those are what make the books right and keep them enterable.

---

## 7. Risks to Watch

- **Double-entry correctness is the whole product.** `PostEntry` gets tested first and hardest.

- **The test database is not the production database.** Tests run on sqlite `:memory:`. Money-as-integers makes amounts identical on both engines, but the **deferred `SUM(functional_amount_minor) = 0` constraint cannot be expressed in sqlite.** Add a CI job running against PostgreSQL and pin the balance-constraint, statement, closing and row-locking tests to it. Nothing depending on deferred constraints, window functions or `SELECT … FOR UPDATE` is "passing" until it passes there.

- **FX is the sharpest edge, and it got sharper.** Four failure modes deserving dedicated tests: (1) summing `amount_minor` instead of `functional_amount_minor` and getting a "balanced" mixed-currency entry that is nothing of the sort — `expect()->toBalance()` guards this; (2) a posted line's translated amount moving because someone corrected the rate table — the per-line snapshot prevents it; (3) **an own-account conversion (D9) posted without its FX gain/loss line**, which the deferred constraint will reject at the database — make sure the action produces a comprehensible error rather than a constraint violation; (4) **revaluation double-counting** — a revalued balance that is then settled must not recognise the same movement twice.

- **Import idempotency.** Overlapping statement re-uploads are not an edge case, they are the normal workflow. `dedupe_hash` is load-bearing, and the test for it should re-import the same file twice and assert zero new rows.

- **Transfer double-counting.** Both sides of every internal transfer appear on two statements (D10). This is the single most likely way the books silently gain phantom money.

- **Tax defaults.** D5's whole point is that tax is usually off. If a required-field design ships, users will enter nonsense to get past it and the VAT return will be built on garbage.

- **Statutory rates in code.** D15 exists because PAYE bands change. Any rate that appears as a PHP constant is a bug with a delayed fuse.

- **Concurrency — resolved.** Per-entry balance needs no locking (single transaction). The two real races: closing a period while someone posts into it (row-lock the `periods` row in both transactions), and `entry_number` allocation (sequence or atomic counter, never `MAX + 1`).

- **Scope creep before MVP.** Phases 0–3 are the MVP. Everything else waits, including things this document now describes in detail.

---

## 8. Package Status

| Package | Purpose | Status |
|---|---|---|
| `laravel/fortify` | Auth (login, 2FA, passkeys) | **Installed** — do not add Breeze |
| `pestphp/pest` | Testing | **Installed** |
| `laravel/scout` (database driver) | Transaction search | **Installed** |
| `laravel/wayfinder` | Typed route functions for React | **Installed** |
| `larastan/larastan`, `laravel/pint` | Static analysis, formatting | **Installed**, enforced in CI |
| `spatie/laravel-activitylog` | Audit trail | **Installed** 2026-08-08 (D17) — used only through `App\Actions\Audit\RecordAudit` |
| `spatie/laravel-permission` | Roles & permissions | **Needs approval** — probably unnecessary; enum + policies suffice |
| `openspout/openspout` | Excel statement import & export | **Needs approval** — now Phase 4, and load-bearing for R3 if any institution exports `.xlsx` rather than CSV |
| `mpdf/mpdf` | PDF export | **Needs approval** — Phase 10 |
| `recharts` (npm) | Dashboard charts | **Needs approval** — Phase 10 |
