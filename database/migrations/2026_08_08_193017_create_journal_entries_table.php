<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();

            // Human-readable and stable, e.g. JE-2026-0142. Allocated from
            // journal_entry_sequences under a row lock; the unique index is the
            // backstop that turns any race that slips through into a failed
            // transaction rather than a duplicate.
            $table->string('entry_number', 32)->unique();

            // The entry date determines the period — not posted_at, not created_at.
            // A December invoice keyed in January belongs to December.
            $table->date('entry_date');
            $table->foreignId('period_id')->constrained('periods')->restrictOnDelete();

            $table->text('memo')->nullable();
            $table->string('reference')->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            // *This* entry reverses *that* one. Unique, so an entry can be reversed
            // at most once — reversing twice would double the correction.
            $table->foreignId('reverses_journal_entry_id')
                ->nullable()
                ->unique()
                ->constrained('journal_entries')
                ->restrictOnDelete();

            // Polymorphic provenance. Every subsidiary module — payroll, depreciation,
            // investments, invoices, imports, transfers — tags what it posted, so a
            // number in the general ledger can always be traced back to what produced
            // it (principle 7).
            $table->nullableMorphs('source');

            $table->timestamps();

            $table->index('entry_date');
            $table->index('period_id');
        });

        // The trial balance, every statement and every account register read posted
        // entries only, so the hot path is worth its own partial index. Supported by
        // both engines, but with no Blueprint API for the predicate.
        DB::statement(
            "CREATE INDEX journal_entries_posted_index ON journal_entries (entry_date, id) WHERE status = 'posted'"
        );

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // A posted entry records when it was posted. posted_by stays nullable on
        // purpose: scheduled runs (depreciation, accretion) have no user, and the
        // activity log records the same null causer rather than inventing one.
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_posted_attribution CHECK '
            ."(status <> 'posted' OR posted_at IS NOT NULL)"
        );

        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_known CHECK '
            ."(status IN ('draft', 'posted'))"
        );

        // An entry cannot reverse itself.
        DB::statement(
            'ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_no_self_reversal CHECK '
            .'(reverses_journal_entry_id IS NULL OR reverses_journal_entry_id <> id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
