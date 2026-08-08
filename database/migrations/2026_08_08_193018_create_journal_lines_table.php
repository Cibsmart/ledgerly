<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            // The currency this line was transacted in. May differ from the
            // functional currency and from other lines on the same entry.
            $table->string('currency_code', 3);

            // Signed, debit positive, in the *transaction* currency. A single signed
            // column rather than a debit/credit pair: with two currencies per line
            // the pair becomes four amount columns and four places a translation bug
            // can hide. Reports render debit/credit from the sign.
            $table->bigInteger('amount_minor');

            // Snapshotted at post time and never re-derived. 1 when the line is
            // already functional, so every line translates the same way and reports
            // need no special case. Correcting exchange_rates must change what
            // future postings default to and nothing about this row.
            $table->decimal('exchange_rate', 20, 10);

            // Signed, functional currency. The only column a trial balance sums, and
            // the only one that can net to zero across a mixed-currency entry.
            $table->bigInteger('functional_amount_minor');

            $table->text('memo')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index('journal_entry_id');

            // The account register and every balance query filter by account and
            // order by the entry's date, which is reached through this pair.
            $table->index(['account_id', 'journal_entry_id']);
        });

        // contact_id (the AR/AP sub-ledger key) and tax_rate_id arrive with the
        // `contacts` and `tax_rates` tables in Phases 2 and 6, and the reconciliation
        // columns (cleared_at, reconciliation_id) in Phase 4. Adding a nullable
        // column with no table to point at buys nothing today.

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amount_nonzero CHECK (amount_minor <> 0)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_rate_positive CHECK (exchange_rate > 0)');

        // Translation preserves direction: a debit in USD is a debit in NGN. This
        // catches a sign flip in the conversion, which is otherwise invisible —
        // a wrong-signed line still balances if its counterpart is wrong too.
        DB::statement(
            'ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_sign_agrees CHECK '
            .'(sign(amount_minor) = sign(functional_amount_minor))'
        );

        // The payoff for the single-signed-amount decision, and the guarantee behind
        // invariant 1: an unbalanced entry cannot exist in the database, whatever
        // wrote it.
        //
        // DEFERRABLE INITIALLY DEFERRED is what makes it usable — the check runs at
        // COMMIT rather than per row, so an entry is legitimately unbalanced while
        // its first line is being inserted and must only balance once the
        // transaction closes.
        //
        // A CHECK constraint cannot express this: CHECK is per row and cannot
        // aggregate across an entry's lines. A deferred constraint trigger is the
        // only form PostgreSQL offers. It has no sqlite equivalent, which is why
        // App\Actions\Ledger\PostEntry asserts balance itself rather than leaning on
        // this, and why the test that asserts this rejection is pinned to pgsql.
        // OR REPLACE because a function is not owned by any table: dropping
        // journal_lines takes its trigger with it but leaves this behind, so a
        // plain CREATE fails the second time `migrate:fresh` runs against the
        // same database — which is every test run after the first.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_must_balance() RETURNS trigger AS $$
            DECLARE
                entry_id bigint;
                imbalance bigint;
            BEGIN
                entry_id := COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);

                SELECT COALESCE(SUM(functional_amount_minor), 0)
                  INTO imbalance
                  FROM journal_lines
                 WHERE journal_entry_id = entry_id;

                -- Zero lines means the entry was deleted and its lines cascaded;
                -- that balances trivially and is not this trigger's business.
                IF imbalance <> 0 THEN
                    RAISE EXCEPTION
                        'Journal entry % does not balance: functional amounts sum to % minor units, not zero.',
                        entry_id, imbalance
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER journal_lines_must_balance
                AFTER INSERT OR UPDATE OR DELETE ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_must_balance ON journal_lines');
            DB::unprepared('DROP FUNCTION IF EXISTS journal_entry_must_balance()');
        }

        Schema::dropIfExists('journal_lines');
    }
};
