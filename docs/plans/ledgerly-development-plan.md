# Ledgerly — Development Plan

**A double-entry financial management app for internal business use**

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

**Not installed, and not to be installed without approval:** `spatie/laravel-permission`, `spatie/laravel-activitylog`, `barryvdh/laravel-dompdf`, `maatwebsite/excel`, `recharts`. Earlier drafts of this plan listed these as Phase 0 installs. They are now decisions, not givens — see each phase.

**Do not install Breeze.** Fortify is already in place and the starter kit's auth pages are built against it.

**Database state:** only the starter-kit tables exist (`users`, `cache`, `jobs`, `passkeys`, 2FA columns). No accounting tables yet — Phase 1 starts from a clean slate.

---

## 1. Architecture Principles

These hold across every phase below:

1. **Single source of truth.** The income statement, balance sheet, and cash flow statement are never separately maintained — they are all *derived views* computed from the same journal entries. If two reports can disagree, the design is wrong.
   *Clarification:* a query-level **cache** that is rebuilt from `journal_lines` and invalidated on posting is permitted. A **column** that holds a balance or statement figure as its own source of truth is not. The test: if the cache were dropped, could every number be recomputed exactly? If no, it's a second source of truth.
2. **Immutable journal entries.** Once posted, an entry is never edited or deleted. Corrections happen via a reversing entry. This is what makes the audit trail trustworthy.
3. **Debits always equal credits.** Enforced in a service layer (`JournalEntryService`) before anything touches the database — never trust the UI alone to keep this balanced.
   *Added:* the service guarantees *balance*; the database guarantees *line sanity* via `CHECK` constraints (no negative amounts, no line that is both a debit and a credit, no zero-amount line). Defence in depth — a future job, command or console script that bypasses the service must still be unable to write a nonsense row.
4. **Money is never a float.** All monetary columns use PostgreSQL `NUMERIC(15,2)`. Never `FLOAT`/`DOUBLE`. All arithmetic happens server-side in PHP on strings/decimals (`bcmath`) or integer cents — never in JavaScript. The frontend formats; it does not compute.
5. **Everything is attributable.** Every entry, posting, approval and reversal records who did it and when.

---

## 2. Decisions to Make Before Phase 1

Each of these is cheap to decide now and expensive to change after `journal_lines` has data in it. Write the answer inline here before writing the migrations.

| # | Decision | Options | Notes |
|---|---|---|---|
| D1 | **Line shape** | (a) `debit` + `credit` columns, both `NUMERIC(15,2)`, `CHECK (debit >= 0 AND credit >= 0 AND (debit = 0) <> (credit = 0))` — matches how accountants read a ledger. (b) single signed `amount`, debit positive, `CHECK (amount <> 0)` — makes the balance assertion `SUM(amount) = 0`, trivially checkable in SQL and by a deferred constraint trigger. | Either is defensible. (b) is harder to get wrong; (a) is easier to read. Decide once — the whole service and every report depend on it. |
| D2 | **Year-end close** | (a) Post explicit **closing entries** at year end, moving revenue/expense balances into retained earnings. (b) Never close; **derive** "current year earnings" on every balance sheet. | This is the #1 source of "the balance sheet doesn't balance" bugs. (a) is conventional and auditable; (b) means no closing entries but a permanently more complex balance sheet query. Either way you need a designated **Retained Earnings** account in the seeded chart. |
| D3 | **Currency** | Single-currency (record the code anyway, on both `accounts` and `journal_entries`) vs. multi-currency from the start. | Multi-currency retrofitted into a live ledger is the most expensive change in this domain. Single-currency is almost certainly right — but record the currency column now and write down the assumption. |
| D4 | **AR/AP scope** | (a) Journal entries only — "income" means cash received. (b) Track receivables/payables: invoices and bills with later settlement matching. | If you ever need to answer "who owes us money," you need (b). It roughly doubles Phase 2. Decide now whether it's in the MVP or an explicit exclusion. |
| D5 | **Tax (VAT/WHT)** | In scope or explicitly out. | Currently absent from the whole plan. If in scope, transaction forms need tax lines and you need designated tax-liability accounts in the seeded chart. |

**Assumed unless changed:** D3 single-currency, D4 (a) journal entries only, D5 out of scope for the MVP. D1 and D2 have no safe default — pick them.

---

## 3. Core Data Model

Column lists below are the *minimum* — the ones marked **(new)** were missing from the first draft and are painful to retrofit.

### `accounts` — chart of accounts

`id, code (new), name, type, parent_id, currency (new), cash_flow_classification (new), is_cash_equivalent (new), is_active, description, timestamps`

- **`code`** — every real chart of accounts is numbered (1000–1999 assets, 2000–2999 liabilities, …). It drives report ordering and is how an accountant refers to an account. Unique, sortable, seeded.
- **`type`** — backed PHP enum: `Asset`, `Liability`, `Equity`, `Revenue`, `Expense`.
- **`normal_balance` is deliberately NOT a column.** It is a pure function of `type` — store it and you have two sources of truth that can drift, which violates principle 1. Derive it from the enum.
- **`cash_flow_classification`** (`operating` / `investing` / `financing`, nullable) and **`is_cash_equivalent`** — the indirect cash flow statement cannot be built without per-account classification. Adding these later means reopening the accounts migration *and* re-classifying every existing account by hand.
- **Accounts are archivable, never deletable** once any line references them. `is_active = false` hides them from entry forms; historical reports still resolve them.

### `journal_entries` — the transaction header

`id, entry_number (new), entry_date, period_id (new), memo, reference (new), status, created_by, posted_by (new), posted_at, reverses_journal_entry_id (renamed), source_type / source_id (new, nullable), timestamps`

- **`entry_number`** — human-readable sequential reference (`JE-2026-0142`). Users and auditors will not navigate by database id. Allocation must be race-safe (see Risks).
- **`entry_date` determines the period**, not `posted_at` and not `created_at`. Spell this out in the service; it's the difference between a correct and an incorrect trial balance.
- **`period_id`** resolved from `entry_date` at post time and stored, so "is this period closed?" is an indexed check on every post rather than a date-range scan.
- **`reverses_journal_entry_id`** — renamed from `reversed_entry_id`, which was directionally ambiguous. It means *this entry reverses that one*. Add a **unique index** on it: an entry may be reversed at most once, or immutability is meaningless.
- **`status`** — `draft`, `posted`. Do **not** add a `reversed` status; whether an entry has been reversed is derived from the existence of a reversing entry. Phase 5's approval workflow adds `pending_approval` if D-level approval is built.
- **`source_type` / `source_id`** — polymorphic origin (recurring template, bank import, investment purchase). Gives every generated entry a traceable provenance in one direction, instead of a `linked_journal_entry_id` hanging off each source table.

### `journal_lines` — the debit/credit lines

`id, journal_entry_id, account_id, {per D1: debit + credit, or signed amount}, memo, contact_id (nullable), timestamps`

- DB `CHECK` constraints per D1. Non-negotiable — this is principle 3's second line of defence.
- **Reconciliation columns (`cleared_at`, `reconciliation_id`)** are deliberately deferred to Phase 6 rather than added speculatively; adding two nullable columns to this table later is a cheap migration, unlike the constraint shape.

### `periods` — fiscal periods

`id, fiscal_year (new), name (new), start_date, end_date, closed_at, closed_by, timestamps`

- Non-overlapping, contiguous. Enforce with a constraint or a service-level check plus a test.
- Closing a period and posting into it race with each other — see Risks for the resolution.

### `contacts` — vendors/customers

`id, name, is_vendor (changed), is_customer (changed), email, phone, notes, is_active, timestamps`

- The original single `type` column was wrong: a party is routinely both a vendor and a customer. Two booleans (or a tag relation) instead.

### `attachments` — polymorphic

`id, attachable_type, attachable_id, path, original_name, mime_type, size, uploaded_by, timestamps`

- Attaching a receipt to an already-posted entry is **allowed** — an attachment is not ledger data and does not change any number. Log it. Detaching should be logged too.

### `investments` / `investment_valuations` — Phase 4

`investments: id, name, type, amount_invested, acquired_at, currency`
`investment_valuations: id, investment_id, value, valued_at, recorded_by`

- The link to the ledger runs through `journal_entries.source_type/source_id`, not a `linked_journal_entry_id` column here.

### `roles` / `permissions`, `activity_log`

- Phase 1 ships a simple `role` enum on `users` (`admin` / `bookkeeper` / `viewer`) plus Laravel policies. That is enough for the whole MVP.
- `spatie/laravel-permission` and `spatie/laravel-activitylog` are **not installed** and need approval. If activitylog is declined, principle 5 still has to hold — fall back to a hand-rolled `activity_log` table written by the ledger service.

---

## Phase 0 — Foundation (Est. 1–2 days)

*Most of the original Phase 0 is already done by the starter kit. What remains:*

- [ ] Answer **D1 and D2** in section 2 (blocking — Phase 1 migrations depend on both)
- [ ] Confirm the PostgreSQL connection and run the baseline migration
- [ ] **Fix CI:** the workflow runs `composer setup`, which runs `artisan migrate --force` against `.env.example` — and `.env.example` points at PostgreSQL while the workflow has no Postgres service container. Add one (this also unblocks the Postgres test job in Phase 1)
- [ ] Decide on `spatie/laravel-activitylog` vs. a hand-rolled `activity_log` table — attribution is needed from the *first* posted entry, so this cannot wait for Phase 5
- [ ] Base layout: sidebar nav + top bar, built from the existing Radix/shadcn components already in `resources/js/components`
- [ ] Draft the default chart-of-accounts seeder content (the seeder itself lands in Phase 1, once `accounts` exists) — including **Retained Earnings** and, per D2, **Opening Balance Equity**

**Deliverable:** decisions recorded, CI green against PostgreSQL, a shell layout to hang Phase 1 screens on.

---

## Phase 1 — Chart of Accounts & Ledger Core (Est. 2–2.5 weeks)

*This phase absorbed authorization, periods and indexing from later phases. Every one of them is something Phase 2/3 code would otherwise have to be rewritten to accommodate.*

- [ ] Migrations: `accounts`, `journal_entries`, `journal_lines`, `periods` — with the `CHECK` constraints from principle 3 and the columns from section 3
- [ ] **Indexes, in these migrations, not in Phase 7:** `journal_lines(account_id)`, `journal_lines(journal_entry_id)`, `journal_entries(entry_date)`, `journal_entries(period_id)`, and a partial index on posted entries. They cost nothing now and they change how you write every Phase 3 query
- [ ] `JournalEntryService` — the single place entries are created, posted and reversed. Validates balance, assigns the period from `entry_date`, rejects posting into a closed period, records attribution, allocates `entry_number`
- [ ] **Period locking, here rather than Phase 5** — CLAUDE.md lists it as a non-negotiable invariant, so it cannot be two phases of code away
- [ ] **Authorization scaffolding, here rather than Phase 5** — `role` enum on `users` + policies for accounts, entries and periods. Retrofitting gates across every controller afterwards is exactly how a Viewer ends up able to post. The *package*, the approval workflow and the audit UI still belong in Phase 5
- [ ] Chart of Accounts UI: list, create, edit, archive; parent/sub-account nesting; code-ordered
- [ ] Seed the default chart of accounts (editable afterward)
- [ ] **Golden fixture company** — a seeder building ~50 entries across two fiscal periods, with a hand-verified trial balance, income statement and balance sheet committed alongside it. Every subsequent phase asserts against it. This is the single highest-leverage artifact in the plan
- [ ] Tests: unbalanced entries rejected; posted entries immutable; reversal correct and single-use; closed periods reject posts; property-based test — for any randomly generated set of balanced entries, the trial balance nets to zero

**Deliverable:** accounts can be defined and balanced entries posted through code, the numbers provably reconcile, and there is a regression baseline for everything that follows.

---

## Phase 2 — Transactions & Day-to-Day Entry (Est. 2 weeks)

- [ ] **Opening balances** — a first-class path for entering the balances you're migrating in from, posted through the service against Opening Balance Equity. This was missing entirely from the first draft and it is the first thing needed after seeding the chart
- [ ] Transaction entry UI — simplified forms for common cases ("record an expense," "record income") that generate the correct double-entry lines behind the scenes, plus a raw manual journal entry form
- [ ] Contacts (vendors/customers) management, linkable to lines
- [ ] Attachments — receipts/invoices, local disk in dev, S3 in production
- [ ] Transaction list — filter by date range, account, contact, amount; search (Scout is already installed with the `database` driver — do not add Meilisearch/Typesense)
- [ ] Recurring transactions (rent, subscriptions, payroll) via Laravel's scheduler, generating drafts rather than auto-posting
- [ ] Per D4: invoices/bills, or a written note that receivables tracking is out of scope

**Deliverable:** day-to-day bookkeeping is usable end-to-end through the UI, against real opening balances.

---

## Phase 3 — Core Financial Statements (Est. 2 weeks) — **MVP milestone**

*Split from the original Phase 3, which packed four statements, two export formats and a charting dashboard into 1.5–2 weeks. Cash flow alone is harder than the other three combined.*

- [ ] Trial Balance — sums all account balances, confirms total debits = total credits
- [ ] Income Statement (P&L) — revenue minus expenses, for any date range
- [ ] Balance Sheet — assets = liabilities + equity, as of any date, **including the retained earnings / current-year-earnings treatment chosen in D2**. Without that, the balance sheet stops balancing the moment you cross a fiscal year
- [ ] Every statement test asserts the trial balance still nets to zero, and every statement is asserted against the golden fixture from Phase 1
- [ ] Year-end close mechanics per D2

**Deliverable:** the three core statements generate correctly, tie to each other, and match the fixture. **This is the MVP** — the app can produce correct books.

---

## Phase 3.5 — Cash Flow, Exports & Dashboard (Est. 1.5 weeks)

- [ ] Cash Flow Statement (indirect method), built on the `cash_flow_classification` metadata added in Phase 1
- [ ] Export: PDF and Excel/CSV for every statement — **requires approval for `barryvdh/laravel-dompdf` and `maatwebsite/excel`**. Cheaper alternatives if declined: CSV via a plain streamed response, PDF via the browser's own print stylesheet
- [ ] Dashboard: revenue/expense trends, top expense categories, current cash position — **requires approval for `recharts`**

**Deliverable:** all four statements, exportable, plus an at-a-glance dashboard.

---

## Phase 4 — Investments Module (Est. 1 week)

- [ ] `investments` and `investment_valuations` CRUD
- [ ] Valuation history feeding a value-over-time chart
- [ ] ROI / unrealized gain-loss (current value vs. amount invested) — computed, never stored
- [ ] Initial purchase generates a journal entry via the service, tagged through `source_type`/`source_id`, so it flows onto the balance sheet automatically

**Deliverable:** investments tracked with historical valuations, appearing correctly on the balance sheet.

---

## Phase 5 — Team, Roles & Controls (Est. 1 week)

*The `role` enum, policies and period locking already shipped in Phase 1. What's left is the package migration and the workflow features.*

- [ ] Optionally migrate the role enum to `spatie/laravel-permission` — **only if** granular per-permission control is actually needed; the enum may well be sufficient forever
- [ ] Approval workflow: Bookkeeper-created entries sit in `draft`/`pending_approval` until an Admin posts them
- [ ] Audit trail UI — searchable log of who created, posted, approved, reversed what and when
- [ ] Access-control tests: Viewers cannot post; Bookkeepers cannot unlock closed periods

**Deliverable:** safe for more than one person to use without risking the integrity of the books.

---

## Phase 6 — Bank Reconciliation (Optional / Stretch, Est. 1–2 weeks)

- [ ] Add `cleared_at` / `reconciliation_id` to `journal_lines`
- [ ] CSV import of bank statements
- [ ] Reconciliation screen: match imported bank lines against existing entries, flag unmatched
- [ ] (Further stretch) live bank feed via Plaid/Mono — note that this puts account credentials and third-party data sharing in scope, which is a materially different security conversation

**Deliverable:** less manual re-entry, discrepancies caught early.

---

## Phase 7 — Hardening & Launch (Est. 1 week)

*Testing and indexing moved into the phases that create the code. What's left is genuinely end-of-project work.*

- [ ] Query performance pass against a realistically-sized ledger (100k+ lines) — measure before caching anything, and respect the cache-vs-column rule in principle 1
- [ ] Backup strategy for PostgreSQL: automated daily backups and a **tested restore**. An untested restore is not a backup
- [ ] Staging environment, and a migration rollback story — you cannot re-seed a production ledger
- [ ] Deployment pipeline. **Resolve the target:** the original plan said AWS; Laravel Cloud is the lower-friction path for a Laravel 13 + Inertia app and matches the local Herd setup
- [ ] Basic onboarding docs for the team

**Deliverable:** production-ready, tested, backed up, restorable.

---

## Package List (Reference)

| Package | Purpose | Status |
|---|---|---|
| `laravel/fortify` | Auth (login, 2FA, passkeys) | **Installed** — do not add Breeze |
| `pestphp/pest` | Testing | **Installed** |
| `laravel/scout` (database driver) | Transaction search | **Installed** |
| `laravel/wayfinder` | Typed route functions for React | **Installed** |
| `larastan/larastan`, `laravel/pint` | Static analysis, formatting | **Installed**, enforced in CI |
| `spatie/laravel-activitylog` | Audit trail | **Needs approval** — decide in Phase 0 |
| `spatie/laravel-permission` | Roles & permissions | **Needs approval** — probably unnecessary; enum + policies may suffice |
| `barryvdh/laravel-dompdf` | PDF export | **Needs approval** — Phase 3.5 |
| `maatwebsite/excel` | Excel/CSV export & bank import | **Needs approval** — Phase 3.5 |
| `recharts` (npm) | Dashboard charts | **Needs approval** — Phase 3.5 |

---

## Risks to Watch

- **Double-entry correctness is the whole product.** Under-invest in testing here and every other feature inherits the bug. `JournalEntryService` gets tested first and hardest.

- **The test database is not the production database.** Tests run against sqlite `:memory:` (see `phpunit.xml`), and **sqlite has no real `NUMERIC(15,2)`** — it applies type affinity and can hold money as a float. The one invariant that matters most is therefore being verified on an engine that doesn't implement it. **Add a CI job running the suite against PostgreSQL**, and pin the money, statement and closing tests to it. Anything depending on `NUMERIC` semantics, window functions or full-text search is not "passing" until it has passed there.

- **Money precision.** No float columns, no JS arithmetic on amounts. Use `decimal:2` casts (which return strings) and `bcmath` for arithmetic. Once splits or percentage allocations exist, fix a rounding rule — largest-remainder — so allocations always re-sum to the original.

- **Concurrency — resolved, not deferred.** The first draft left this as "decide early." The answer: per-entry balance needs no locking, since an entry is written inside a single transaction. The two real races are (1) **closing a period while someone is posting into it** — take a row lock on the `periods` row inside the posting transaction, and take the same lock when closing; and (2) **`entry_number` allocation** — use a database sequence or an atomic counter, never `MAX(entry_number) + 1`.

- **Scope creep before MVP.** Phases 1–3 are the MVP. Phase 3.5 onward waits.

- **Retrofit risk.** The columns flagged **(new)** in section 3 — account `code`, `cash_flow_classification`, `currency`, `period_id`, `entry_number` — are all cheap now and expensive after the ledger holds real data. Add them in Phase 1 even if nothing reads them yet.

---

## Suggested Order of Work

Phase 0 → 1 → 2 → 3 is the MVP: **roughly 7–9 weeks** part-time, faster if focused full-time. That is longer than the original 5–7 week estimate for two reasons: Phase 1 now absorbs authorization, period locking, indexing and the golden fixture, and Phase 3 was compressed to the point of fiction.

After the MVP, Phase 3.5 (cash flow + exports + dashboard) is the natural next step, since exports are usually the first thing anyone asks for. Phases 4–7 layer on by whatever you need most urgently — investments vs. multi-user controls vs. reconciliation.
