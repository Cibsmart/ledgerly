<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            $table->string('base_code', 3);
            $table->string('quote_code', 3);

            // How many units of quote_code one unit of base_code buys. NUMERIC
            // rather than an integer because a rate is not money — it has no minor
            // unit, and ten decimal places is what a USD/NGN rate quoted to the
            // kobo needs. Read as a string and handed straight to bcmath in
            // Money::convertTo(); never converted to a float.
            $table->decimal('rate', 20, 10);

            // Effective dating: a rate applies from this date until superseded.
            // PostEntry resolves the latest rate on or before the entry date.
            $table->date('effective_on');

            $table->string('source')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['base_code', 'quote_code', 'effective_on']);

            // The resolution query orders by effective_on descending within a pair.
            $table->index(['base_code', 'quote_code', 'effective_on']);
        });

        // This table is a default, not the authority (D8). A posted line snapshots
        // the rate it used, and never reads this table again — correcting a
        // historical rate changes what future postings default to and nothing
        // about the past. That is invariant 2 applied to FX.

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_positive CHECK (rate > 0)');

        // A self-referential rate is meaningless and would shadow the implicit
        // rate of 1 that a functional-currency line carries.
        DB::statement(
            'ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_distinct_currencies CHECK '
            .'(base_code <> quote_code)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
