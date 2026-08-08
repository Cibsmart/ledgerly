<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // New accounts default to the least privilege the app has. Registration
            // is open to the internal team, and a self-registered user who can post
            // to the books before anyone has looked at them is not a default worth
            // having.
            $table->string('role', 20)->default(Role::Viewer->value)->after('email');
        });

        // Anyone already registered predates roles and is the owner or the internal
        // team — the alternative is locking every existing user out of an app that
        // previously had no restrictions at all.
        DB::table('users')->update(['role' => Role::Admin->value]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
