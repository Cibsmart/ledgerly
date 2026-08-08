<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            // Numbered per docs/plans/chart-of-accounts.md § 1. Unique and sortable,
            // and what report ordering is driven by — hence a string rather than an
            // integer, so a future "1011a" needs no schema change.
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('type', 20);

            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();

            // The currency this account is *denominated* in — set only for the bank
            // and platform balances. Null means "accepts any currency". It constrains
            // what may be posted here; it never replaces the per-line currency_code.
            $table->string('currency_code', 3)->nullable();

            // Whether the balance is a claim to, or obligation for, a fixed number of
            // currency units. Load-bearing from Phase 5 (D7): this is the flag
            // period-end revaluation reads. Getting it wrong on a USD balance
            // misstates the year-end balance sheet.
            $table->boolean('is_monetary')->default(false);

            $table->string('cash_flow_classification', 20)->nullable();
            $table->boolean('is_cash_equivalent')->default(false);

            // Accounts are archivable, never deletable once any line references them.
            $table->boolean('is_active')->default(true);

            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('parent_id');
        });

        // `normal_balance` is deliberately absent: it is a pure function of `type`
        // (see App\Enums\AccountType::normalBalance()), and a column would be a
        // second source of truth that can drift.
        //
        // `is_postable` is deliberately absent too. An account with children cannot
        // be posted to, because posting to a header would double-count it against
        // its own children in any report that sums a subtree. Deriving it from the
        // tree means the rule cannot fall out of step with the tree; PostEntry
        // enforces it.
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
