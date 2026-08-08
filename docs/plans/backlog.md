# Ledgerly — Backlog (index)

**This file is an index, not a plan.** Every planned feature gets its own document in `docs/plans/`; entries below are concise pointers — status, a one-line summary, and only the sequencing that spans tasks. Full diagnosis, design, phase breakdowns, and testing notes live in the linked plan. **Keep new detail in the plan document, not here** — when an entry starts growing, move the detail into its plan doc and leave a pointer.

Plan documents referenced below:

- `ledgerly-development-plan.md` — the roadmap: architecture principles, core data model, and phases 0–11.
- `chart-of-accounts.md` — the default chart the Phase 1 seeder loads, with `is_monetary` and `cash_flow_classification` on every account, plus three open questions that need answering before that seeder is written.

Status verified against the codebase on 2026-08-08. **Phases 0 and 1 are complete; Phase 2 is next — see § 4.** Plan re-scoped against the real business requirements on 2026-07-28 — phase order changed; see below.

---

## Re-scope 2026-07-28 — plan reviewed against Cibsmart Services Ltd's actual needs

The plan was written generically. Reviewed against the eight requirements now recorded as R1–R8 in `ledgerly-development-plan.md` § 1, and re-sequenced. Full detail lives in the plan; the headlines:

- **Bank reconciliation moved from Phase 6 "optional/stretch" to Phase 4.** With 8 account-currency pairs across 5 institutions, statement import is the *primary* data-entry path, not a cleanup tool. Needs a `bank_transactions` staging table with an idempotent `dedupe_hash` — the old `cleared_at`/`reconciliation_id` design supports matching but has nowhere to put an imported row with no entry yet, which is most of them.
- **Investments went from a one-week Phase 4 to a three-to-four-week Phase 9**, and are the one honest compression lever if the schedule needs it.
- **Three modules were missing entirely** and now exist: fixed assets + depreciation (D13), payroll (D15), and the tax layer (D5).
- **Own-account FX conversion (D9) was unmodelled** — a Grey USD→NGN move is a two-currency entry that will not net to zero without an explicit FX gain/loss line, and it is the most frequent FX event in the business.
- **Timeline is now 5–7 months part-time for full coverage**, against 7–9 weeks for the MVP (phases 0–3, unchanged).

## Decisions closed 2026-07-28

- **D2 — year-end close: explicit closing entries.** A closing entry is ledger data, not a stored statement figure, so invariant 3 holds; keeps the balance sheet query simple across fiscal years.
- **D4 — AR/AP: track receivables and payables.** Forced by R8 — UpSub's $600/month hardware deduction is inexpressible without a payable.
- **D5 — tax: in scope but optional per transaction.** VAT-registered and WHT applies both ways, but current partners pay in full with no VAT and no WHT. Tax defaults to *none*, defaults per contact, never mandatory. **D5a (Nigerian input-VAT recoverability) and D5b (who files the VAT return) are still open — confirm with the accountant.**
- **D7 — FX revaluation promoted from post-MVP into Phase 5**, because "Due to UpSub" is an open USD monetary liability crossing the fiscal year-end.
- **D8 — the `exchange_rates` lookup is a default, not the authority.** `PostEntry` accepts an explicit per-line rate; the table is the fallback.
- **D11 — full instrument accounting for investments**, not position-and-valuation. **D12** weighted-average cost basis. **D16** unrealized gains disclosed, not posted.
- **D14 — CSV/Excel from all five institutions.** No PDF statement parsing required.
- **D15 — statutory payroll rates are effective-dated data, never constants.** ⚠️ Confirm the 2026 PAYE bands against the Nigeria Tax Act 2025 before building payroll; do not take rates from the plan document or from model memory.

---

## Decisions made 2026-07-27

Adopted from `seed-hub`, which ships a production double-entry cooperative ledger and had already answered several of these. These **supersede the roadmap where they differ** — the roadmap has been updated in `CLAUDE.md`/`AGENTS.md` but not yet rewritten section by section.

- **Money is integer minor units, not `NUMERIC(15,2)`.** Columns are `bigInteger` suffixed `_minor`, cast to `App\Support\Money`. This replaces the plan's `decimal:2` + `bcmath` approach, and dissolves the plan's own top-listed testing risk — that SQLite has no real `NUMERIC` and can hold money as a float, meaning the most important invariant was being verified on an engine that doesn't implement it. Integers behave identically on both engines. `Money::allocate()` implements the plan's required largest-remainder rounding rule.
- **The ledger write path is an Actions layer, not a service class.** `App\Actions\Ledger\PostEntry` and `ReverseEntry` replace the planned `JournalEntryService`. One class, one `handle()`. This is also what makes the audit guardrail test possible — it needs a scannable directory of single-purpose classes.
- **Immutability is enforced twice.** In the write path *and* in the models, which throw `LogicException` from `booted()` on `updating`/`deleting`. Invariant 2 must survive tinker and seeders, not just well-behaved application code.
- **Audit completeness is a test, not a convention.** `tests/Feature/Architecture/AuditCompletenessTest.php` fails when an action neither audits nor carries a documented exemption.
- **Roles stay an enum with policies.** `spatie/laravel-permission` remains uninstalled; seed-hub models per-role permissions in a `Role::permissions()` match and has not needed the package. Revisit only if per-user permission overrides become a real requirement.

## Decisions made 2026-07-27 (multi-currency)

**Multi-currency is a requirement, not an option.** This reopened D3 and settled D1 with it. Full detail is in `ledgerly-development-plan.md` § 2.

- **D3 — multi-currency with a single functional currency.** Books kept in one functional currency (`config/money.php`, NGN by default); transactions in any configured currency. Every line stores transaction amount + rate used + translated functional amount. **Only functional amounts balance.**
- **D1 — signed `amount_minor`, resolved by D3.** Two amount columns rather than four, and the balance assertion becomes a deferred `SUM(functional_amount_minor) = 0` database constraint — a stronger guarantee than application code. Reports render debit/credit by sign. This is where ledgerly *diverges* from seed-hub, which uses separate debit/credit columns and is single-currency.
- **D6 (new) — `exchange_rates` table, rate snapshotted onto each line.** Bookkeeper-maintained with effective dating; no API feed in the MVP. A posted line never re-reads the table, so correcting a historical rate cannot move history.
- **D7 (new) — realized FX gain/loss only in the MVP.** Seed `7100 FX Gain` and `8100 FX Loss` in Phase 1. Period-end revaluation of open monetary balances is post-MVP, but add `is_monetary` to `accounts` in Phase 1 — retrofitting it means re-classifying every account by hand.

All of D1–D17 are now resolved except **D5a** (Nigerian input-VAT recoverability) and **D5b** (who files the VAT return), both of which need an accountant, not a design decision. Nothing before Phase 6 is blocked on either.

---

## 1. Foundation hardening — ✅ Completed 2026-07-27

`App\Support\Money` (currency-aware, throws on cross-currency arithmetic) + `App\Support\Currency` + `MoneyCast` + `config/money.php`; `Model::preventLazyLoading` and `Relation::enforceMorphMap` in `AppServiceProvider`; `parseModelCastsMethod` and `databaseMigrationsPath` in `phpstan.neon`; `.githooks/pre-commit` wired into `composer setup` via `composer hooks`; audit-completeness architecture test; `expect()->toBalance()` (rejects non-functional amounts); `README.md`. 94 tests passing, PHPStan level 7 clean.

## 2. Phase 0 — Foundation — ✅ Completed 2026-08-08

Plan: `ledgerly-development-plan.md` § Phase 0.

- **CI runs on PostgreSQL.** Service container added to the `ci` job so `composer setup`'s migrate works, plus a second `tests-pgsql` job running the entire suite against PostgreSQL rather than a pinned subset. `tests/Feature/Architecture/DatabaseEngineTest.php` fails if that job ever degrades into a second sqlite run — it asserts the driver against `EXPECTED_DB_DRIVER`, which only CI sets. Also added the `pdo_pgsql` and `bcmath` extensions, neither of which setup-php installs by default and the second of which `Money::convertTo()` needs.
- **D17 — audit storage: `spatie/laravel-activitylog`, approved and installed.** Chosen for application-wide coverage; the ledger's own events are domain events written by hand under either option, but contacts, accounts and settings get model-level logging for free. Everything goes through `App\Actions\Audit\RecordAudit`, which throws when logging is disabled rather than returning null and writing nothing. `buffer.enabled` must stay false and `activitylog:clean` must never be scheduled — both commented in `config/activitylog.php`. **v5 dropped `LogBatch`**, so grouping a payroll or depreciation run's postings needs our own key in `properties`.
- **Base layout.** Sidebar grouped into Ledger / Records / Reports; navigation defined once in `resources/js/lib/navigation.ts` and consumed by both the sidebar and the header layout. Screens that do not exist yet are listed as inert placeholders — giving an item an `href` is what shipping it looks like. Starter-kit repo/docs links removed.
- **Default chart of accounts drafted** — `chart-of-accounts.md`.

**Three questions were raised here for the Phase 1 seeder. Two are now answered — see § 3.** In short: R2's count was off by one, so the seeder ships seven cash accounts and leaves 1018 free; and postability is derived from having children rather than stored in an `is_postable` column. **D5a (input-VAT recoverability) remains open** and still needs the accountant.

**Also fixed:** the local `.env` had `DB_CONNECTION=ledgerly` with MySQL's port and user, so `php artisan` could not boot at all. Restored to the documented PostgreSQL settings; the previous file is at `.env.backup-20260808`.

## 3. Phase 1 — Chart of accounts & ledger core — ✅ Completed 2026-08-08

Plan: `ledgerly-development-plan.md` § Phase 1; `chart-of-accounts.md` for the seeder content. 240 tests green on **both** sqlite and PostgreSQL, PHPStan level 7 clean.

- **Migrations.** `accounts`, `periods`, `exchange_rates`, `journal_entry_sequences`, `journal_entries`, `journal_lines`, plus `role` on `users`. Morph map aliases and the `booted()` immutability guards shipped with the models; indexes (including a partial index on posted entries) shipped with the tables.
- **The deferred balance guarantee is a `CONSTRAINT TRIGGER`, not a `CHECK`.** A CHECK is per row and cannot aggregate across an entry's lines, so `DEFERRABLE INITIALLY DEFERRED` on a trigger function is the only form PostgreSQL offers. Alongside it: `amount_minor <> 0`, `exchange_rate > 0`, and **a sign-agreement check** — a translation that flips a line's sign still balances if its counterpart is flipped too, so the imbalance check alone would never catch it.
- **CHECK constraints are PostgreSQL-only.** SQLite accepts CHECK at `CREATE TABLE` time but has no `ALTER TABLE ADD CONSTRAINT`, and Blueprint exposes no API for the former. The action layer is therefore the guarantee on both engines and the database is the backstop on the one that runs production. `DatabaseConstraintsTest` is pinned to pgsql and writes rows *directly*, bypassing `PostEntry` — routing through the action would prove nothing about the database.
- **`RefreshDatabase` hides deferred constraints.** It wraps each test in a transaction it rolls back, so nothing ever commits and the trigger never fires: a test written the obvious way passes whether the constraint exists or not. `SET CONSTRAINTS ALL IMMEDIATE` forces evaluation. Anyone adding a deferred-constraint test needs this or it is worthless.
- **Entry numbering is a counter row under a row lock, not a sequence.** A PostgreSQL sequence is race-safe but non-transactional, so a rolled-back posting would burn its number and leave a permanent gap in the numbering — the kind of hole an auditor asks about. `MAX + 1` is never used.
- **`PostEntry` / `ReverseEntry` / `ClosePeriod` / `ReopenPeriod` / `CreatePeriod`.** Per-line explicit rate with `exchange_rates` fallback (D8); period resolved from `entry_date` under a row lock that `ClosePeriod` also takes, so close-versus-post cannot interleave. **`ReverseEntry` reuses each original line's snapshotted rate** — re-resolving today's rate would leave a residual indistinguishable from a real FX movement.
- **Roles are an enum plus four policies.** `spatie/laravel-permission` still not needed. Permissions are Gate abilities so `$user->can(...)` works normally. The load-bearing split: Bookkeeper closes periods, only Admin reopens one.
- **Default chart seeded — 70 accounts**, `is_monetary` and `cash_flow_classification` populated on every one, idempotent on `code`.
- **Golden fixture** — `GoldenFixtureSeeder`, 50 entries across January and February 2026, asserted against hand-derived figures in `tests/Fixtures/GoldenFixtureExpectations.php`. Covers the Grey USD→NGN conversion with its FX residual (D9) and the UpSub settlement booking gross revenue against the payable released at its *booked* rate (R8). Deel's ₦12,780,000 balance cannot be reconstructed from its $7,850 and any single rate — that is the property the fixture exists to pin.
- **Chart of Accounts screen** — code-ordered, nested, create/edit/archive/restore, no delete route at all.

### Two open questions from § 2 answered

1. **R2's eighth account-currency pair: there isn't one.** The seeder ships seven (1011–1017) and leaves **1018 free**. R2's count was off by one; adding an eighth institution later is a data insert, not a migration.
2. **Header accounts derive their postability from having children.** No `is_postable` column, enforced in `PostEntry`. A column would be a second source of truth that can drift out of step with the tree.

**D5a (input-VAT recoverability) is still open** and still needs the accountant. It blocks nothing before Phase 6; the chart ships both `1310 Input VAT — Recoverable` and `6210 Input VAT — Expensed`.

### Known gaps, deliberately left

- **No screen for periods or exchange rates.** The actions exist and are tested, but the fiscal calendar comes from `FiscalCalendarSeeder` and rates have no UI at all. Rates are needed the moment anyone records a USD transaction by hand, so this is Phase 2 work, not a nicety.
- **No way to change a user's role.** Registration defaults to `Viewer` (least privilege) and the migration promoted existing users to `Admin`, but there is no screen and no command to promote anyone else. A second team member cannot currently be given posting rights.
- **`JournalLine` refuses *all* updates.** Correct today. Phase 4's `cleared_at` / `reconciliation_id` are metadata about a line rather than the line itself, so that guard will need an explicit allowlist of those two columns — widening it to "anything not an amount" would let a `memo` or an `account_id` through with it.
- **`contact_id` and `tax_rate_id` are not on `journal_lines` yet**, and arrive with the `contacts` and `tax_rates` tables in Phases 2 and 6. A nullable column with no table to point at buys nothing.
- **The "property-based" test is randomised-input, not property-based.** It posts 25 balanced entries at random dates and asserts the ledger nets to zero. No generator library is installed and adding one needs approval.
- **The Chart of Accounts page is not verified to mount client-side.** It renders over real HTTP with correct props and passes `tsc`/ESLint, but confirming the React tree actually mounts needs Playwright and `pest-plugin-browser`, neither of which is installed.

### Also fixed

- **The local `.env` had drifted back to MySQL again** (`DB_CONNECTION=mysql`, port 3306, user root) despite the 2026-07-28 fix recorded in § 2. Restored to the documented PostgreSQL settings; previous file at `.env.backup-mysql-20260808`. Worth finding out what keeps reverting it — MySQL 8 has neither deferred constraints nor constraint triggers, so the entire D1 payoff silently disappears on it.
- **`CREATE FUNCTION` had to become `CREATE OR REPLACE FUNCTION`.** A function is not owned by any table, so dropping `journal_lines` takes its trigger but leaves the function behind, and a plain `CREATE` fails on every `migrate:fresh` after the first.

## 4. Phase 2 — Books live — **next to build**

Plan: `ledgerly-development-plan.md` § Phase 2. **Unblocked.** Start with **opening balances**, posting through `PostEntry` against `3200 Opening Balance Equity` — it is the first thing needed after seeding the chart, and every other Phase 2 screen is entering transactions on top of balances that have to be right first.

Sequence, and why:

1. **Opening balances**, per above. `3200` should return to zero once they are complete; a non-zero balance is the live "you are not finished" signal, which is why it is a real account rather than netted into Retained Earnings.
2. **Exchange rate management** — pulled forward from nowhere in particular, because it is a Phase 1 gap: nobody can record a USD transaction by hand until rates can be entered. Small screen, `ManageExchangeRates` permission already exists.
3. **`financial_accounts` CRUD** and their GL mapping (R2) — the seven cash accounts already exist in the chart and need their real-world counterparts.
4. **Transfers (D10) and `ConvertCurrency` (D9)** — the two highest-volume entry types in this business. The FX conversion is the sharpest test case in the suite, and the golden fixture already contains a hand-verified example of it to check the action against.
5. **Manual journal entry form**, plus the simplified "record an expense" / "record income" forms.
6. **Contacts** with tax defaults (D5), **attachments** (R7), **transaction list** with Scout search, **recurring transactions generating drafts only**.

Also needed somewhere in here, and currently missing entirely: **a way to change a user's role**. Registration defaults to Viewer, so a second team member cannot be given posting rights at all today.

**Deliverable:** every one of R1–R8 is *recordable*, by hand where no module exists yet. The live Jul–Dec 2026 UpSub schedule and the monthly payroll become enterable from this point, well before their dedicated modules exist.

## 4b. Phase 3 — Core statements (MVP milestone)

Plan: `ledgerly-development-plan.md` § Phase 3. Every statement test asserts `expect($lines)->toBalance()` **and** asserts against the Phase 1 golden fixture — `GoldenFixtureExpectations::statementTotals()` already carries the hand-derived revenue, expense, net profit, asset, liability and equity figures for exactly this purpose.

## 5. Phases 4–11 — Post-MVP, now in scope

Reconciliation (4), AR/AP + FX (5), tax (6), fixed assets (7), payroll (8), investments (9), cash flow + exports + dashboard (10), hardening (11). **These are no longer "not to be built"** — R1–R8 require all of them. They are sequenced after the MVP, not excluded from it. Approval workflows remain out of scope.
