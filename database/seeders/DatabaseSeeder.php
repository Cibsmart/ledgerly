<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            // Someone has to be able to set the books up. Registration still
            // defaults to Viewer; this is the local development account.
            'role' => Role::Admin,
        ]);

        $this->call([
            ChartOfAccountsSeeder::class,
            FiscalCalendarSeeder::class,
        ]);
    }
}
