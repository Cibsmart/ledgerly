<?php

namespace App\Actions\Accounts;

use App\Actions\Audit\RecordAudit;
use App\Enums\AccountType;
use App\Enums\AuditEvent;
use App\Enums\CashFlowClassification;
use App\Models\Account;
use App\Models\User;
use App\Support\Ledger\AccountHierarchyException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Add an account to the chart.
 *
 * Note what creating a child does: it turns the parent into a header, and a
 * header cannot be posted to. Doing that to an account that already has lines
 * would strand them under an account nothing new can be posted to, so it is
 * refused — the fix is a new sibling, not a new child.
 */
final class CreateAccount
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Named parameters rather than an attribute array: the caller is a
     * controller handing over freshly validated request data, and an array
     * shape declared here would be a promise about that data the type system
     * cannot check at the boundary it crosses.
     */
    public function handle(
        string $code,
        string $name,
        AccountType $type,
        ?int $parentId = null,
        ?string $currencyCode = null,
        bool $isMonetary = false,
        ?CashFlowClassification $cashFlowClassification = null,
        bool $isCashEquivalent = false,
        ?string $description = null,
        ?User $user = null,
    ): Account {
        $actor = $user ?? Auth::user();

        return DB::transaction(function () use (
            $code, $name, $type, $parentId, $currencyCode, $isMonetary,
            $cashFlowClassification, $isCashEquivalent, $description, $actor
        ): Account {
            if ($parentId !== null) {
                $this->assertParentCanAdopt($parentId);
            }

            $account = Account::query()->create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_id' => $parentId,
                'currency_code' => $currencyCode,
                'is_monetary' => $isMonetary,
                'cash_flow_classification' => $cashFlowClassification,
                'is_cash_equivalent' => $isCashEquivalent,
                'is_active' => true,
                'description' => $description,
            ]);

            $this->audit->handle(
                event: AuditEvent::Created,
                subject: $account,
                properties: [
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'is_monetary' => $account->is_monetary,
                    'cash_flow_classification' => $account->cash_flow_classification?->value,
                    'currency_code' => $account->currency_code,
                ],
                description: "Created account {$account->code} {$account->name}",
                causer: $actor,
                logName: 'chart-of-accounts',
            );

            return $account;
        });
    }

    private function assertParentCanAdopt(int $parentId): void
    {
        /** @var Account $parent */
        $parent = Account::query()->whereKey($parentId)->lockForUpdate()->firstOrFail();

        if ($parent->lines()->exists()) {
            throw new AccountHierarchyException(
                "Account [{$parent->code} {$parent->name}] already has journal lines posted to it, so "
                .'it cannot be given children — doing so would make it a header that nothing further '
                .'can be posted to, while its existing lines stay where they are. Create a sibling '
                .'account instead.'
            );
        }
    }
}
