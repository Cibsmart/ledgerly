<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\CashFlowClassification;
use App\Models\Account;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(1000, 9999),
            // Only the code is unique; nothing constrains an account name, and
            // drawing unique words risks exhausting Faker's dictionary.
            'name' => ucfirst(fake()->word()).' '.fake()->word(),
            'type' => AccountType::Asset,
            'parent_id' => null,
            'currency_code' => null,
            'is_monetary' => false,
            'cash_flow_classification' => CashFlowClassification::Operating,
            'is_cash_equivalent' => false,
            'is_active' => true,
            'description' => null,
        ];
    }

    public function ofType(AccountType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function asset(): static
    {
        return $this->ofType(AccountType::Asset);
    }

    public function liability(): static
    {
        return $this->ofType(AccountType::Liability);
    }

    public function equity(): static
    {
        return $this->ofType(AccountType::Equity);
    }

    public function revenue(): static
    {
        return $this->ofType(AccountType::Revenue);
    }

    public function expense(): static
    {
        return $this->ofType(AccountType::Expense);
    }

    /**
     * A cash or bank balance: monetary, a cash equivalent, and denominated in
     * one currency — the shape of every account in chart-of-accounts.md § 3.1.
     */
    public function cash(?string $currency = null): static
    {
        return $this->state([
            'type' => AccountType::Asset,
            'currency_code' => $currency ?? Currency::functional()->code,
            'is_monetary' => true,
            'is_cash_equivalent' => true,
            'cash_flow_classification' => null,
        ]);
    }

    /**
     * Denominated in a single currency, so it rejects lines in any other.
     */
    public function denominatedIn(string $currency): static
    {
        return $this->state(['currency_code' => strtoupper($currency)]);
    }

    public function archived(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Makes the parent a header account, and therefore unpostable.
     */
    public function childOf(Account $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'type' => $parent->type,
        ]);
    }
}
