# Ledgerly — Backlog (index)

**This file is an index, not a plan.** Every planned feature gets its own document in `docs/plans/`; entries below are concise pointers — status, a one-line summary, and only the sequencing that spans tasks. Full diagnosis, design, phase breakdowns, and testing notes live in the linked plan. **Keep new detail in the plan document, not here** — when an entry starts growing, move the detail into its plan doc and leave a pointer.

Plan documents referenced below:

- `ledgerly-development-plan.md` — the roadmap: architecture principles, core data model, and phases 0–11.
- `chart-of-accounts.md` — the default chart the Phase 1 seeder loads, with `is_monetary` and `cash_flow_classification` on every account, plus three open questions that need answering before that seeder is written.

Status verified against the codebase on 2026-08-08. **Plan re-scoped against the real business requirements on 2026-07-28 — phase order changed; see below.**

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

**Three questions from the chart need answering before the Phase 1 seeder is written**, though none blocks starting Phase 1:

1. **R2 says eight account-currency pairs but enumerates seven** (Zenith ×2, Grey ×2, Deel, Moniepoint, Kuda). Code 1018 is left unassigned rather than inventing an account.
2. **Header accounts have no `is_postable` column.** Recommendation: derive it — an account with children cannot be posted to — and enforce in `PostEntry`, rather than adding a column that can drift out of step with the tree.
3. **D5a input-VAT recoverability** still needs the accountant; the chart supports either answer via `1310 Input VAT — Recoverable` and `6210 Input VAT — Expensed`.

**Also fixed:** the local `.env` had `DB_CONNECTION=ledgerly` with MySQL's port and user, so `php artisan` could not boot at all. Restored to the documented PostgreSQL settings; the previous file is at `.env.backup-20260808`.

## 3. Phase 1 — Chart of accounts & ledger core

Plan: `ledgerly-development-plan.md` § Phase 1, and `chart-of-accounts.md` for the seeder content. **Unblocked — ready to start, and the next thing to build.** When built:

- Add the `journal_entry`, `journal_line`, `account`, and `period` aliases to `Relation::enforceMorphMap()` **before** any rows exist.
- Give `JournalLine` its `booted()` immutability guards in the same commit as the model.
- Ship the `exchange_rates` table and the deferred `SUM(functional_amount_minor) = 0` constraint together with `journal_lines` — the constraint is the payoff for D1 and is not a later addition.
- `PostEntry` takes an **optional explicit rate per line** (D8), falling back to `exchange_rates`.
- Seed the full chart from the plan's `accounts` section — not just `7100 FX Gain` / `8100 FX Loss`, but AR/AP controls, WHT and VAT accounts, PAYE and pension payables, Due-to-Partner, and one asset account per financial account. Populate `is_monetary` and `cash_flow_classification` on **every** account; `is_monetary` is now load-bearing, since D7 promotes revaluation into Phase 5.

## 4. Phases 2–3 — Books live, then statements (MVP milestone)

Plan: `ledgerly-development-plan.md` §§ Phase 2, Phase 3. Every statement test asserts `expect($lines)->toBalance()`. Phase 2 must ship `ConvertCurrency` (D9) and transfers (D10) — the two highest-volume entry types in this business. From the end of Phase 2 the manual entry form can express everything in R1–R8 by hand, so no live obligation (the Jul–Dec 2026 UpSub schedule, monthly payroll) waits on a later module.

## 5. Phases 4–11 — Post-MVP, now in scope

Reconciliation (4), AR/AP + FX (5), tax (6), fixed assets (7), payroll (8), investments (9), cash flow + exports + dashboard (10), hardening (11). **These are no longer "not to be built"** — R1–R8 require all of them. They are sequenced after the MVP, not excluded from it. Approval workflows remain out of scope.
