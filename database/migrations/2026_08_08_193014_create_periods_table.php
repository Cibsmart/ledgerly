<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('fiscal_year');
            $table->string('name');

            // Inclusive bounds. Periods must be non-overlapping and contiguous;
            // that is asserted by App\Actions\Ledger\CreatePeriod rather than by a
            // constraint, since expressing non-overlap in the schema needs a
            // PostgreSQL exclusion constraint sqlite cannot mirror.
            $table->date('start_date');
            $table->date('end_date');

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fiscal_year', 'name']);
            $table->index('start_date');
            $table->index('end_date');
        });

        // CHECK constraints are added on PostgreSQL only. SQLite accepts CHECK at
        // CREATE TABLE time but has no ALTER TABLE ADD CONSTRAINT, and Blueprint
        // exposes no API for the former. This is the split the plan already
        // anticipates: the database is the backstop on the engine that runs
        // production, and the action layer is the guarantee on both. Tests that
        // assert a constraint rejection are pinned to PostgreSQL.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Posting resolves a period by `entry_date BETWEEN start_date AND end_date`,
        // which silently matches nothing when the bounds are inverted.
        DB::statement('ALTER TABLE periods ADD CONSTRAINT periods_dates_ordered CHECK (start_date <= end_date)');

        // A closed period records who closed it and when, together or not at all —
        // invariant 5 applied to the close.
        DB::statement(
            'ALTER TABLE periods ADD CONSTRAINT periods_closed_attribution CHECK '
            .'(closed_at IS NULL OR closed_by IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
