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
 * Archive an account, or bring one back.
 *
 * Archiving is the only removal the chart supports: an account carries the
 * meaning of every line posted to it, and deleting one makes history
 * unreadable. An archived account keeps its balance and keeps appearing on
 * reports that cover the periods it was used in — it simply cannot be posted
 * to again.
 */
final class ArchiveAccount
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Account $account, ?User $user = null, ?string $reason = null): Account
    {
        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($account, $actor, $reason): Account {
            if (! $account->is_active) {
                return $account;
            }

            // Archiving a header while its children are still in use would
            // leave the tree with an inactive node in the middle of it, which
            // every subtree report then has to special-case.
            $activeChildren = $account->children()->where('is_active', true)->pluck('code')->all();

            if ($activeChildren !== []) {
                throw new AccountHierarchyException(
                    "Account [{$account->code} {$account->name}] still has active child accounts ("
                    .implode(', ', $activeChildren).'). Archive those first.'
                );
            }

            $account->is_active = false;
            $account->save();

            $this->audit->handle(
                event: AuditEvent::Archived,
                subject: $account,
                properties: array_filter([
                    'code' => $account->code,
                    'name' => $account->name,
                    'had_lines' => $account->lines()->exists(),
                    'reason' => $reason,
                ], fn (mixed $value): bool => $value !== null),
                description: "Archived account {$account->code} {$account->name}",
                causer: $actor,
                logName: 'chart-of-accounts',
            );

            return $account;
        });
    }

    public function restore(Account $account, ?User $user = null): Account
    {
        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($account, $actor): Account {
            if ($account->is_active) {
                return $account;
            }

            // A child cannot be active under an archived parent, or the tree
            // gains a live branch hanging off a dead one.
            if ($account->parent_id !== null) {
                /** @var Account $parent */
                $parent = Account::query()->findOrFail($account->parent_id);

                if (! $parent->is_active) {
                    throw new AccountHierarchyException(
                        "Account [{$account->code} {$account->name}] sits under archived parent "
                        ."[{$parent->code} {$parent->name}]. Restore the parent first."
                    );
                }
            }

            $account->is_active = true;
            $account->save();

            $this->audit->handle(
                event: AuditEvent::Restored,
                subject: $account,
                properties: ['code' => $account->code, 'name' => $account->name],
                description: "Restored account {$account->code} {$account->name}",
                causer: $actor,
                logName: 'chart-of-accounts',
            );

            return $account;
        });
    }
}
