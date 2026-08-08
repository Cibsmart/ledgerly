<?php

namespace App\Actions\Accounts;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditEvent;
use App\Models\Account;
use App\Models\User;
use App\Support\Ledger\AccountHierarchyException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Edit an account.
 *
 * Most fields are freely editable — renaming an account changes nothing about
 * the lines posted to it. Three are not, once history exists:
 *
 * - **`type`**, because it decides which statement the account appears on and
 *   which side its balance falls. Changing it retrospectively moves every
 *   historical line between the balance sheet and the income statement.
 * - **`currency_code`**, because lines already posted in the old denomination
 *   would become invalid against the new one.
 * - **`parent_id` onto an account that has lines**, for the reason in
 *   CreateAccount.
 *
 * `is_monetary` and `cash_flow_classification` stay editable deliberately: they
 * are classifications used by reports computed from the lines, not properties
 * of the lines themselves, and getting one wrong at seed time must be fixable.
 */
final class UpdateAccount
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Account $account, array $attributes, ?User $user = null): Account
    {
        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($account, $attributes, $actor): Account {
            $hasHistory = $account->lines()->exists();

            if ($hasHistory) {
                $this->assertImmutableFieldsUnchanged($account, $attributes);
            }

            if (array_key_exists('parent_id', $attributes)
                && (int) $attributes['parent_id'] !== (int) $account->parent_id
                && $attributes['parent_id'] !== null) {
                $this->assertParentCanAdopt((int) $attributes['parent_id'], $account);
            }

            $before = $account->only([
                'code', 'name', 'type', 'parent_id', 'currency_code',
                'is_monetary', 'cash_flow_classification', 'is_cash_equivalent', 'description',
            ]);

            $account->fill($attributes);

            if (! $account->isDirty()) {
                return $account;
            }

            $changed = array_keys($account->getDirty());
            $account->save();

            $this->audit->handle(
                event: AuditEvent::Updated,
                subject: $account,
                properties: [
                    'code' => $account->code,
                    'changed' => $changed,
                    'before' => array_map($this->scalar(...), array_intersect_key($before, array_flip($changed))),
                    'after' => array_map($this->scalar(...), $account->only($changed)),
                ],
                description: "Updated account {$account->code} {$account->name}",
                causer: $actor,
                logName: 'chart-of-accounts',
            );

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertImmutableFieldsUnchanged(Account $account, array $attributes): void
    {
        $type = $attributes['type'] ?? null;
        $typeValue = $type instanceof \BackedEnum ? $type->value : $type;

        if ($typeValue !== null && $typeValue !== $account->type->value) {
            throw new AccountHierarchyException(
                "Account [{$account->code} {$account->name}] has journal lines, so its type cannot be "
                ."changed from {$account->type->value} to {$typeValue}. That would move every line "
                .'already posted to it between the balance sheet and the income statement. Archive '
                .'this account and open a new one of the right type.'
            );
        }

        $currency = $attributes['currency_code'] ?? null;

        if (array_key_exists('currency_code', $attributes) && $currency !== $account->currency_code) {
            throw new AccountHierarchyException(
                "Account [{$account->code} {$account->name}] has journal lines denominated in "
                .($account->currency_code ?? 'any currency').', so its denomination cannot be changed.'
            );
        }
    }

    private function assertParentCanAdopt(int $parentId, Account $account): void
    {
        if ($parentId === $account->id) {
            throw new AccountHierarchyException(
                "Account [{$account->code}] cannot be its own parent."
            );
        }

        /** @var Account $parent */
        $parent = Account::query()->whereKey($parentId)->lockForUpdate()->firstOrFail();

        if ($this->isDescendantOf($parent, $account)) {
            throw new AccountHierarchyException(
                "Account [{$parent->code} {$parent->name}] is below [{$account->code}] in the chart, "
                .'so making it the parent would create a cycle.'
            );
        }

        if ($parent->lines()->exists()) {
            throw new AccountHierarchyException(
                "Account [{$parent->code} {$parent->name}] has journal lines posted to it and cannot "
                .'be given children.'
            );
        }
    }

    private function isDescendantOf(Account $candidate, Account $ancestor): bool
    {
        $current = $candidate;

        while ($current->parent_id !== null) {
            if ($current->parent_id === $ancestor->id) {
                return true;
            }

            $current = Account::query()->findOrFail($current->parent_id);
        }

        return false;
    }

    private function scalar(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
