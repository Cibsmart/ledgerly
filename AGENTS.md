<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/scout (SCOUT) - v11
- laravel/wayfinder (WAYFINDER) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

# Ledgerly

A double-entry financial management app that runs the books of **Cibsmart Services Ltd** (single Nigerian entity, NGN functional currency, owner + small team). The full roadmap lives in `docs/plans/ledgerly-development-plan.md` — read it before starting work on a new module, and read its **§ 1** first: the eight real business requirements (R1–R8) are what every scope decision resolves against. In outline: 8 account-currency pairs across 5 institutions (Zenith USD/NGN, Grey USD/NGN, Deel USD, Moniepoint NGN, Kuda NGN), statement import as the primary data-entry path, USD and NGN partner revenue, investments (T-bills, bonds, stocks, real estate), monthly payroll for staff and contractors, expenses with receipts on file, and partner-funded hardware recovered by instalment deduction.

**This is accounting software. The correctness rules below outrank convenience, speed of delivery, and your own judgment about a "simpler" way to do it.** If a requested change would violate one, say so before writing code.

## Accounting Invariants (Non-Negotiable)

1. **Debits must equal credits — in the functional currency.** Enforced in the action layer before anything touches the database, and by a deferred `SUM(functional_amount_minor) = 0` constraint in PostgreSQL. Request validation and UI checks are conveniences, never the guarantee. An unbalanced entry must be impossible to persist. **A mixed-currency entry does not balance in transaction-currency terms and never will** — never assert balance over `amount_minor`.
2. **Posted entries are immutable.** Never update or delete a posted `journal_entry` or its `journal_lines`. Corrections happen only via a reversing entry that references the original through `reversed_entry_id`. Do not add an "edit posted entry" path, even if asked for one, without first flagging that it breaks the audit trail.
3. **Financial statements are derived, never stored.** The trial balance, income statement, balance sheet, and cash flow statement are all computed from `journal_lines`. Never introduce a column, cache, or table that holds a statement figure as its own source of truth — if two reports can disagree, the design is wrong. Query-level caching is fine only if it is invalidated by posting.
4. **Money is never a float, and never currency-less.** Every monetary column is a `bigInteger` of **integer minor units** suffixed `_minor`, cast to the immutable `App\Support\Money`, which carries its `App\Support\Currency`. Never `float`/`double`, and never `decimal`/`NUMERIC` — integers behave identically on SQLite and PostgreSQL, which is what makes the `:memory:` test suite trustworthy for the invariant that matters most. All arithmetic goes through `Money`; never unwrap to `->minor` to do maths by hand, and never compute amounts in JavaScript. **`Money` throws on cross-currency arithmetic — do not catch it and coerce.** That exception means a conversion is missing, not that a rounding rule is needed. Splits use `Money::allocate()` (largest-remainder, always re-sums). `Money` serializes to `{minor, currency, formatted}`.
5. **Everything is attributable.** Every entry, posting, approval, and reversal records who did it and when (`created_by`, `posted_at`, and the activity log).
6. **Closed periods are closed.** Once a `period` has `closed_at`, no entry may be posted into it. Corrections go into a later open period as reversing entries.

## Application Architecture

Business operations live in `app/Actions/<Domain>/<VerbNoun>.php` — one single-purpose class with one public `handle()` method and constructor-promoted dependencies. **Controllers validate, delegate to an action, and render. They do not contain business logic.** Supporting layers:

```
app/
├── Actions/   Single-purpose domain operations (PostEntry, ReverseEntry, ClosePeriod, …)
├── Casts/     Eloquent casts, including MoneyCast
├── Concerns/  Shared model/validation traits
├── Enums/     Backed enums for every status, type, role, and permission
├── Http/      Controllers, form requests, middleware
├── Models/    Eloquent models
└── Support/   Value objects and domain services (Money, report builders, …)
```

Conventions: backed string enums for every status and type, each with a `label()` method; `#[Fillable([...])]` attribute rather than a `$fillable` property; casts declared in a `casts()` method (`phpstan.neon` sets `parseModelCastsMethod` so Larastan reads the body); every polymorphic model registered in `Relation::enforceMorphMap()` in `AppServiceProvider` **before** any rows exist.

## The Ledger Write Path

All journal entry creation goes through `App\Actions\Ledger\PostEntry`, and all corrections through `App\Actions\Ledger\ReverseEntry`. Controllers, jobs, commands, seeders, factories, and tests must not build `journal_lines` directly — go through the action so balance validation and attribution cannot be bypassed. If you need a shortcut for a test fixture, add a factory state that calls the action.

Enforcement is layered, deliberately. The action asserts balance before writing; the `JournalLine` and `JournalEntry` models throw `LogicException` from `booted()` on `updating` and `deleting`, so invariant 2 holds even from tinker or a careless seeder. Do not remove either layer because the other exists.

## Foreign Currency

- **The rate used is an input to the posting, never a lookup afterwards.** `PostEntry` snapshots the rate onto each line. Nothing may re-derive a posted line's functional amount from the rate table — correcting a historical rate must change what future postings use and nothing about the past. This is invariant 2 applied to FX.
- **`exchange_rates` is a default, not the authority.** `PostEntry` accepts an explicit rate per line and falls back to the table only when none is given. A USD→NGN conversion inside Grey executes at a specific negotiated rate, and that rate — not the day's table entry — is what gets snapshotted.
- **A conversion between two of your own accounts needs an explicit FX gain/loss line.** It is a two-currency entry, so it will not net to zero in functional terms on its own; `ConvertCurrency` computes the residual and posts it to `7100 FX Gain` / `8100 FX Loss`. This is the most frequent FX event in the business — never treat it as an edge case.
- **`Money::convertTo()` takes an explicit rate string.** It is the only place bcmath appears, and it rounds half away from zero.
- Lines already in the functional currency store a rate of 1, not null — so every line translates the same way and reports need no special case.
- FX gain/loss is **realized** on settlement of a foreign-currency balance. **Period-end revaluation of open monetary balances is in scope** (plan Phase 5) — the business carries USD receivables and payables across the fiscal year-end, so `is_monetary` on `accounts` is load-bearing and must be populated correctly on every account.

## The Audit Trail

Storage is `spatie/laravel-activitylog` (approved and installed 2026-08-08), but **nothing calls the `activity()` helper directly.** Every state change goes through `App\Actions\Audit\RecordAudit`, which takes an `App\Enums\AuditEvent`, a subject, and context, and **throws when logging is disabled** — `ActivityLogger::log()` returns null and writes nothing in that case, and an entry that posts without attribution violates invariant 5. Record the audit inside the same transaction as the change it describes.

Two config settings are load-bearing and commented as such in `config/activitylog.php`: `buffer.enabled` must stay false (buffering moves the write outside the posting transaction), and `activitylog:clean` must never be scheduled (this trail sits behind filed accounts). Note that activitylog v5 dropped `LogBatch` — grouping a payroll or depreciation run's postings needs our own key in `properties`.

## Guardrail Tests

`tests/Feature/Architecture/AuditCompletenessTest.php` fails when any class in `app/Actions` neither references `RecordAudit` nor appears in its exemption array. **Adding an exemption is a deliberate, reviewable act — each entry carries a one-line reason.** Do not add one to make a test pass; if an action mutates state, make it audit.

## Testing Priorities

Per the plan's risk section, double-entry correctness is the whole product. In order of value:

1. `PostEntry` / `ReverseEntry` — unbalanced entries rejected, posted entries immutable, reversals correct.
2. Statement generation — every statement test asserts the trial balance still nets to zero via `expect($lines)->toBalance()`.
3. Access control — Viewers cannot post, Bookkeepers cannot unlock closed periods.

Tests run against sqlite `:memory:` (see `phpunit.xml`) for speed. Because money is stored as integers, amounts behave identically on both engines — but any test whose result depends on PostgreSQL deferred constraints, window functions, full-text search, or row-locking semantics must be verified against PostgreSQL before you report it as passing. **CI runs the whole suite on both engines**: the `tests-pgsql` job sets `DB_CONNECTION=pgsql` as a real environment variable, which wins because PHPUnit skips an `<env>` entry whose variable is already set. `DatabaseEngineTest` fails if that ever stops holding and the job quietly degrades into a second sqlite run. To reproduce it locally, prefix `php artisan test` with `DB_CONNECTION=pgsql DB_DATABASE=ledgerly_testing EXPECTED_DB_DRIVER=pgsql`.

Feature tests are organised by domain area (`tests/Feature/<Domain>/`). Unit tests extend the application `TestCase` without `RefreshDatabase`, since value objects read config but need no database.

## Local Environment

- **Database:** PostgreSQL via DBngin — `127.0.0.1:5432`, database `ledgerly`, user `postgres`, empty password. There is no sqlite fallback; do not switch `DB_CONNECTION` back to sqlite.
- **Currency:** **multi-currency is a requirement, not an option.** The books are kept in one *functional* currency (`config/money.php`, NGN by default); transactions may be in any configured currency. Every journal line stores its transaction amount, the exchange rate used, and a translated functional amount. Changing a currency's `scale`, or the functional currency, on a populated ledger would silently corrupt every stored amount.
- **Git hooks:** `composer hooks` points `core.hooksPath` at `.githooks`, which formats staged PHP with Pint on commit. `composer setup` runs it. Bypass once with `git commit --no-verify`.
- **Serving:** Herd serves the app at `http://ledgerly.test`. Never run `artisan serve`. Use the `get-absolute-url` tool before sharing a URL.
- **Search:** Scout with the `database` driver (`SCOUT_DRIVER=database`). Do not introduce Meilisearch, Typesense, or Algolia without approval.
- **Monitoring:** Nightwatch is installed but `NIGHTWATCH_TOKEN` is empty locally, so nothing is ingested until a token is set.

## Dependencies Not Yet Installed

The plan names these, but they are **not** installed. Ask before adding any of them, and do not write code that imports them: `spatie/laravel-permission`, `mpdf/mpdf`, `openspout/openspout`, `recharts` (npm).

## Phase Discipline

Phases 0–3 (foundation → ledger core → books live → statements) are the MVP; do not build later phases unless explicitly asked, even where the data model anticipates them.

**But "later" no longer means "never."** The plan was re-scoped on 2026-07-28 against the real business requirements, and statement import/reconciliation (Phase 4), receivables/payables and FX revaluation (5), tax (6), fixed assets and depreciation (7), payroll (8) and investments (9) are all now in scope — they are sequenced after the MVP, not excluded from it. Only approval workflows remain out of scope. Reconciliation in particular moved *up* from "optional stretch": with 8 account-currency pairs across 5 institutions, statement import is how transactions get into the ledger.

**Every subsidiary module posts through the ledger.** Payroll, depreciation, investments, invoices and imports call `PostEntry` and tag provenance via `source_type`/`source_id`. A module that can produce a number the general ledger does not know about is a second source of truth, and violates invariant 3.

**Statutory rates are effective-dated data, never PHP constants** — PAYE bands, pension percentages, VAT and WHT rates. Confirm current Nigerian rates from an authoritative source before building the tax or payroll modules; do not take them from a plan document or from memory.

## Agent Instruction Files

`CLAUDE.md` and `AGENTS.md` are kept byte-identical. `php artisan boost:update` regenerates only the Laravel Boost guidelines block at the top of each file; everything below it is hand-maintained. If you edit one, mirror it to the other in the same change.
