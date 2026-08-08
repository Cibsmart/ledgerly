<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One counter row per fiscal year, backing the JE-2026-0001 numbering.
        //
        // A table rather than a PostgreSQL sequence for two reasons. It behaves
        // identically on both test engines, and — more usefully — a sequence is
        // non-transactional: a rolled-back posting would still consume its number
        // and leave a permanent gap in the entry numbering, which is exactly the
        // kind of hole an auditor asks about. Allocation takes a row lock inside
        // the posting transaction, so a rollback returns the number.
        //
        // MAX(entry_number) + 1 is never used: two concurrent postings both read
        // the same maximum and the unique index rejects the loser.
        Schema::create('journal_entry_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('fiscal_year')->primary();
            $table->unsignedInteger('next_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_sequences');
    }
};
