<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Map every Permission case onto a Gate ability.
     *
     * Roles are an enum rather than spatie/laravel-permission rows, but the
     * check still goes through the Gate so that `$user->can(Permission::PostEntries)`,
     * the `can:` middleware and `@can` in a view all work the ordinary way.
     * Model-specific rules — "this period is already closed" — belong in the
     * policies, which layer on top of these.
     */
    protected function configureAuthorization(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => $user->hasPermission($permission)
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        // Polymorphic relations store these aliases instead of fully-qualified class
        // names, so a model can be renamed or moved without a data migration. Every
        // model reachable through a morph relation (attachments, audit subjects,
        // journal entry sources) must be registered here when it is introduced —
        // adding an alias after rows exist means rewriting stored type strings.
        Relation::enforceMorphMap([
            'user' => User::class,
            'account' => Account::class,
            'period' => Period::class,
            'journal_entry' => JournalEntry::class,
            'journal_line' => JournalLine::class,
            'exchange_rate' => ExchangeRate::class,
        ]);
    }
}
