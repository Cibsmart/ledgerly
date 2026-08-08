import { Form, Head, router } from '@inertiajs/react';
import { Archive, Pencil, Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import AccountController from '@/actions/App/Http/Controllers/AccountController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/accounts';

type Option = { value: string; label: string };

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    normal_balance: 'debit' | 'credit';
    parent_id: number | null;
    currency_code: string | null;
    is_monetary: boolean;
    cash_flow_classification: string | null;
    is_cash_equivalent: boolean;
    is_active: boolean;
    description: string | null;
    is_postable: boolean;
    has_lines: boolean;
    depth: number;
};

type Options = {
    types: Option[];
    cashFlows: Option[];
    currencies: Option[];
};

type PageProps = {
    accounts: Account[];
    includeArchived: boolean;
    options: Options;
    can: { create: boolean };
};

const selectClasses =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

export default function AccountsIndex({
    accounts,
    includeArchived,
    options,
    can,
}: PageProps) {
    const [editing, setEditing] = useState<Account | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Chart of Accounts" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Chart of Accounts"
                        description="Every account the ledger can post to, in code order. Accounts are archived, never deleted."
                    />

                    {can.create && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-4" />
                            New account
                        </Button>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="archived"
                        checked={includeArchived}
                        onCheckedChange={(checked) =>
                            router.get(
                                index().url,
                                checked ? { archived: 1 } : {},
                                { preserveScroll: true, preserveState: true },
                            )
                        }
                    />
                    <Label htmlFor="archived" className="text-sm font-normal">
                        Show archived accounts
                    </Label>
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">Code</th>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">Type</th>
                                <th className="px-4 py-2 font-medium">
                                    Currency
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Cash flow
                                </th>
                                <th className="px-4 py-2 text-right font-medium">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <AccountRow
                                    key={account.id}
                                    account={account}
                                    onEdit={() => setEditing(account)}
                                />
                            ))}

                            {accounts.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-10 text-center text-muted-foreground"
                                    >
                                        No accounts yet. Seed the default chart,
                                        or add the first one.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {creating && (
                <AccountDialog
                    onClose={() => setCreating(false)}
                    accounts={accounts}
                    options={options}
                />
            )}

            {editing !== null && (
                // Keyed so the uncontrolled fields and the boolean state reset
                // when a different account is opened.
                <AccountDialog
                    key={editing.id}
                    onClose={() => setEditing(null)}
                    account={editing}
                    accounts={accounts}
                    options={options}
                />
            )}
        </>
    );
}

function AccountRow({
    account,
    onEdit,
}: {
    account: Account;
    onEdit: () => void;
}) {
    return (
        <tr className="border-b last:border-0 hover:bg-muted/30">
            <td className="px-4 py-2 font-mono tabular-nums">{account.code}</td>
            <td className="px-4 py-2">
                <div
                    className="flex flex-wrap items-center gap-2"
                    style={{ paddingLeft: `${account.depth * 1.25}rem` }}
                >
                    <span
                        className={
                            account.is_postable
                                ? ''
                                : 'font-medium text-muted-foreground'
                        }
                    >
                        {account.name}
                    </span>

                    {/* Derived from having children, never a stored flag. */}
                    {!account.is_postable && (
                        <Badge
                            variant="outline"
                            title="Has child accounts, so it cannot be posted to directly"
                        >
                            Header
                        </Badge>
                    )}
                    {account.is_monetary && (
                        <Badge
                            variant="secondary"
                            title="Revalued at period end"
                        >
                            Monetary
                        </Badge>
                    )}
                    {!account.is_active && (
                        <Badge variant="outline">Archived</Badge>
                    )}
                </div>
            </td>
            <td className="px-4 py-2">
                {account.type_label}
                <span className="ml-2 text-xs text-muted-foreground">
                    {account.normal_balance === 'debit' ? 'Dr' : 'Cr'}
                </span>
            </td>
            <td className="px-4 py-2 text-muted-foreground">
                {account.currency_code ?? 'Any'}
            </td>
            <td className="px-4 py-2 text-muted-foreground">
                {account.cash_flow_classification ??
                    (account.is_cash_equivalent ? 'Cash equivalent' : '—')}
            </td>
            <td className="px-4 py-2">
                <div className="flex justify-end gap-1">
                    <Button variant="ghost" size="sm" onClick={onEdit}>
                        <Pencil className="size-4" />
                        <span className="sr-only">Edit {account.code}</span>
                    </Button>

                    <Form
                        {...(account.is_active
                            ? AccountController.archive.form(account.id)
                            : AccountController.restore.form(account.id))}
                        options={{ preserveScroll: true }}
                    >
                        <Button variant="ghost" size="sm" type="submit">
                            {account.is_active ? (
                                <Archive className="size-4" />
                            ) : (
                                <RotateCcw className="size-4" />
                            )}
                            <span className="sr-only">
                                {account.is_active ? 'Archive' : 'Restore'}{' '}
                                {account.code}
                            </span>
                        </Button>
                    </Form>
                </div>
            </td>
        </tr>
    );
}

function AccountDialog({
    onClose,
    account,
    accounts,
    options,
}: {
    onClose: () => void;
    account?: Account;
    accounts: Account[];
    options: Options;
}) {
    const isEdit = account !== undefined;

    // Type and denomination are frozen once an account carries history:
    // changing either would move or invalidate lines already posted to it.
    const locked = account?.has_lines ?? false;

    // Booleans are held in state and submitted as hidden inputs. A Radix
    // checkbox submits nothing at all when unchecked, which would arrive as a
    // missing field rather than as false.
    const [isMonetary, setIsMonetary] = useState(account?.is_monetary ?? false);
    const [isCashEquivalent, setIsCashEquivalent] = useState(
        account?.is_cash_equivalent ?? false,
    );

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? `Edit ${account.code}` : 'New account'}
                    </DialogTitle>
                    <DialogDescription>
                        {locked
                            ? 'This account has journal lines, so its type and denomination are fixed. Archive it and open a new one if either needs to change.'
                            : 'Set the monetary flag and cash-flow classification on every account — period-end revaluation and the cash flow statement both read them.'}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...(isEdit
                        ? AccountController.update.form(account.id)
                        : AccountController.store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="is_monetary"
                                value={isMonetary ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name="is_cash_equivalent"
                                value={isCashEquivalent ? '1' : '0'}
                            />

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="code">Code</Label>
                                    <Input
                                        id="code"
                                        name="code"
                                        defaultValue={account?.code}
                                        required
                                        placeholder="6120"
                                    />
                                    <InputError message={errors.code} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="type">Type</Label>
                                    <select
                                        id="type"
                                        name="type"
                                        defaultValue={
                                            account?.type ?? 'expense'
                                        }
                                        disabled={locked}
                                        className={selectClasses}
                                    >
                                        {options.types.map((type) => (
                                            <option
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.type} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={account?.name}
                                    required
                                    placeholder="Internet & Communications"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="parent_id">Parent</Label>
                                <select
                                    id="parent_id"
                                    name="parent_id"
                                    defaultValue={account?.parent_id ?? ''}
                                    className={selectClasses}
                                >
                                    <option value="">None (top level)</option>
                                    {accounts
                                        .filter(
                                            (option) =>
                                                option.id !== account?.id,
                                        )
                                        .map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.code} — {option.name}
                                            </option>
                                        ))}
                                </select>
                                <p className="text-xs text-muted-foreground">
                                    Giving an account children makes it a
                                    header, which can no longer be posted to.
                                </p>
                                <InputError message={errors.parent_id} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="currency_code">
                                        Denomination
                                    </Label>
                                    <select
                                        id="currency_code"
                                        name="currency_code"
                                        defaultValue={
                                            account?.currency_code ?? ''
                                        }
                                        disabled={locked}
                                        className={selectClasses}
                                    >
                                        <option value="">Any currency</option>
                                        {options.currencies.map((currency) => (
                                            <option
                                                key={currency.value}
                                                value={currency.value}
                                            >
                                                {currency.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.currency_code}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="cash_flow_classification">
                                        Cash flow
                                    </Label>
                                    <select
                                        id="cash_flow_classification"
                                        name="cash_flow_classification"
                                        defaultValue={
                                            account?.cash_flow_classification ??
                                            ''
                                        }
                                        className={selectClasses}
                                    >
                                        <option value="">
                                            None (cash balance or header)
                                        </option>
                                        {options.cashFlows.map((flow) => (
                                            <option
                                                key={flow.value}
                                                value={flow.value}
                                            >
                                                {flow.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={
                                            errors.cash_flow_classification
                                        }
                                    />
                                </div>
                            </div>

                            <div className="space-y-3 rounded-lg border p-3">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="is_monetary"
                                        checked={isMonetary}
                                        onCheckedChange={(checked) =>
                                            setIsMonetary(checked === true)
                                        }
                                    />
                                    <div className="grid gap-0.5">
                                        <Label
                                            htmlFor="is_monetary"
                                            className="font-normal"
                                        >
                                            Monetary
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            A claim to, or obligation for, a
                                            fixed number of currency units.
                                            Drives period-end revaluation.
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="is_cash_equivalent"
                                        checked={isCashEquivalent}
                                        onCheckedChange={(checked) =>
                                            setIsCashEquivalent(
                                                checked === true,
                                            )
                                        }
                                    />
                                    <div className="grid gap-0.5">
                                        <Label
                                            htmlFor="is_cash_equivalent"
                                            className="font-normal"
                                        >
                                            Cash equivalent
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            Part of what the cash flow statement
                                            reconciles to.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {isEdit ? 'Save changes' : 'Create account'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

AccountsIndex.layout = {
    breadcrumbs: [{ title: 'Chart of Accounts', href: index() }],
};
