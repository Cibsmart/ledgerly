<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\UpdateAccount;
use App\Enums\AccountType;
use App\Enums\CashFlowClassification;
use App\Http\Requests\Accounts\AccountStoreRequest;
use App\Http\Requests\Accounts\AccountUpdateRequest;
use App\Models\Account;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chart of accounts screen.
 *
 * Validate, delegate to an action, render. The business rules — what may be
 * reparented, what may be archived, what happens to history — all live in the
 * actions, because a controller is only one of the ways they are reached.
 */
class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Account::class);

        $includeArchived = $request->boolean('archived');

        $accounts = Account::query()
            ->withCount(['children', 'lines'])
            ->when(! $includeArchived, fn ($query) => $query->where('is_active', true))
            ->inChartOrder()
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'normal_balance' => $account->normalBalance()->value,
                'parent_id' => $account->parent_id,
                'currency_code' => $account->currency_code,
                'is_monetary' => $account->is_monetary,
                'cash_flow_classification' => $account->cash_flow_classification?->value,
                'is_cash_equivalent' => $account->is_cash_equivalent,
                'is_active' => $account->is_active,
                'description' => $account->description,
                // Derived, not stored: an account with children is a header and
                // cannot be posted to.
                'is_postable' => $account->isPostable(),
                'has_lines' => $account->lines_count > 0,
                'depth' => 0,
            ])
            ->values();

        return Inertia::render('accounts/index', [
            'accounts' => $this->withDepth(array_values($accounts->all())),
            'includeArchived' => $includeArchived,
            'options' => [
                'types' => array_map(
                    fn (AccountType $type): array => ['value' => $type->value, 'label' => $type->label()],
                    AccountType::cases(),
                ),
                'cashFlows' => array_map(
                    fn (CashFlowClassification $c): array => ['value' => $c->value, 'label' => $c->label()],
                    CashFlowClassification::cases(),
                ),
                'currencies' => array_map(
                    fn (Currency $currency): array => [
                        'value' => $currency->code,
                        'label' => "{$currency->code} — {$currency->name}",
                    ],
                    array_values(Currency::all()),
                ),
            ],
            'can' => [
                'create' => $request->user()?->can('create', Account::class) ?? false,
            ],
        ]);
    }

    public function store(AccountStoreRequest $request, CreateAccount $createAccount): RedirectResponse
    {
        $this->authorize('create', Account::class);

        $validated = $request->validated();

        $account = $createAccount->handle(
            code: (string) $validated['code'],
            name: (string) $validated['name'],
            type: AccountType::from((string) $validated['type']),
            parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            currencyCode: $validated['currency_code'] ?? null,
            isMonetary: (bool) $validated['is_monetary'],
            cashFlowClassification: isset($validated['cash_flow_classification'])
                ? CashFlowClassification::from((string) $validated['cash_flow_classification'])
                : null,
            isCashEquivalent: (bool) $validated['is_cash_equivalent'],
            description: $validated['description'] ?? null,
            user: $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :code created.', ['code' => $account->code]),
        ]);

        return to_route('accounts.index');
    }

    public function update(
        AccountUpdateRequest $request,
        Account $account,
        UpdateAccount $updateAccount,
    ): RedirectResponse {
        $this->authorize('update', $account);

        $updateAccount->handle($account, $request->validated(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :code updated.', ['code' => $account->code]),
        ]);

        return to_route('accounts.index');
    }

    /**
     * Archive rather than delete — the chart supports no removal that would
     * make posted history unreadable.
     */
    public function archive(
        Request $request,
        Account $account,
        ArchiveAccount $archiveAccount,
    ): RedirectResponse {
        $this->authorize('archive', $account);

        $archiveAccount->handle($account, $request->user(), $request->string('reason')->value() ?: null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :code archived.', ['code' => $account->code]),
        ]);

        return to_route('accounts.index');
    }

    public function restore(
        Request $request,
        Account $account,
        ArchiveAccount $archiveAccount,
    ): RedirectResponse {
        $this->authorize('restore', $account);

        $archiveAccount->restore($account, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :code restored.', ['code' => $account->code]),
        ]);

        return to_route('accounts.index');
    }

    /**
     * Annotate each row with its depth in the tree, so the screen can indent
     * without recursing over nested payloads.
     *
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function withDepth(array $accounts): array
    {
        $byId = [];

        foreach ($accounts as $account) {
            $byId[$account['id']] = $account;
        }

        foreach ($accounts as $index => $account) {
            $depth = 0;
            $parentId = $account['parent_id'];

            while ($parentId !== null && isset($byId[$parentId]) && $depth < 10) {
                $depth++;
                $parentId = $byId[$parentId]['parent_id'];
            }

            $accounts[$index]['depth'] = $depth;
        }

        return $accounts;
    }
}
