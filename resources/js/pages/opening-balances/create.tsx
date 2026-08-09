import { Form, Head } from '@inertiajs/react';
import { CircleCheck, CircleAlert, Plus, X } from 'lucide-react';
import { useState } from 'react';
import OpeningBalanceController from '@/actions/App/Http/Controllers/OpeningBalanceController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create } from '@/routes/opening-balances';

type Option = { value: string; label: string };

type MoneyValue = { minor: number; currency: string; formatted: string };

type Account = {
    id: number;
    code: string;
    name: string;
    type_label: string;
    currency_code: string | null;
    normal_balance: 'debit' | 'credit';
};

type RecordedRun = {
    id: number;
    entry_number: string;
    entry_date: string;
    memo: string | null;
    line_count: number;
    reversed_by: string | null;
};

type PageProps = {
    accounts: Account[];
    equity: { code: string; name: string; balance: MoneyValue };
    recorded: RecordedRun[];
    functionalCurrency: string;
    currencies: Option[];
};

type Row = { key: number; accountId: string };

const selectClasses =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

let nextKey = 0;
const newRow = (): Row => ({ key: nextKey++, accountId: '' });

export default function OpeningBalancesCreate({
    accounts,
    equity,
    recorded,
    functionalCurrency,
    currencies,
}: PageProps) {
    const [rows, setRows] = useState<Row[]>([newRow()]);

    const isComplete = equity.balance.minor === 0;

    return (
        <>
            <Head title="Opening Balances" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Opening Balances"
                    description="Bring the balances the business already carries onto the books. Each one is posted as an ordinary journal entry against Opening Balance Equity — immutable once posted, and corrected by reversing it."
                />

                {/* The whole point of the screen. While this is non-zero the
                    opening balances are unfinished. Computed from posted lines
                    on every render, never stored. */}
                <div
                    className={`flex flex-wrap items-center gap-3 rounded-xl border p-4 ${
                        isComplete
                            ? 'border-emerald-500/40 bg-emerald-500/5'
                            : 'border-amber-500/40 bg-amber-500/5'
                    }`}
                >
                    {isComplete ? (
                        <CircleCheck className="size-5 shrink-0 text-emerald-600 dark:text-emerald-500" />
                    ) : (
                        <CircleAlert className="size-5 shrink-0 text-amber-600 dark:text-amber-500" />
                    )}

                    <div className="flex-1">
                        <p className="font-medium">
                            {equity.code} {equity.name}:{' '}
                            <span className="font-mono tabular-nums">
                                {equity.balance.formatted}
                            </span>
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {isComplete
                                ? 'Opening Balance Equity nets to zero, so every balance entered so far has a counterpart. Nothing is outstanding.'
                                : 'A non-zero balance means the opening balances are not finished — some accounts still have their side of the entry to come.'}
                        </p>
                    </div>
                </div>

                <Form
                    {...OpeningBalanceController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setRows([newRow()])}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="as_of">As at</Label>
                                    <Input
                                        id="as_of"
                                        name="as_of"
                                        type="date"
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        The entry date, which decides the period
                                        it lands in. That period must be open.
                                    </p>
                                    <InputError message={errors.as_of} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="memo">Memo</Label>
                                    <Input
                                        id="memo"
                                        name="memo"
                                        placeholder="Opening balances"
                                    />
                                    <InputError message={errors.memo} />
                                </div>
                            </div>

                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2 className="font-medium">Balances</h2>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setRows((current) => [
                                                ...current,
                                                newRow(),
                                            ])
                                        }
                                    >
                                        <Plus className="size-4" />
                                        Add account
                                    </Button>
                                </div>

                                <p className="text-sm text-muted-foreground">
                                    Enter a positive amount and choose the side
                                    it falls on. The balancing line against{' '}
                                    {equity.code} is worked out on the server —
                                    amounts are never calculated in the browser.
                                </p>

                                <InputError message={errors.lines} />

                                {rows.map((row, index) => (
                                    <BalanceRow
                                        key={row.key}
                                        index={index}
                                        accounts={accounts}
                                        currencies={currencies}
                                        functionalCurrency={functionalCurrency}
                                        accountId={row.accountId}
                                        onAccountChange={(accountId) =>
                                            setRows((current) =>
                                                current.map((item) =>
                                                    item.key === row.key
                                                        ? { ...item, accountId }
                                                        : item,
                                                ),
                                            )
                                        }
                                        onRemove={
                                            rows.length > 1
                                                ? () =>
                                                      setRows((current) =>
                                                          current.filter(
                                                              (item) =>
                                                                  item.key !==
                                                                  row.key,
                                                          ),
                                                      )
                                                : undefined
                                        }
                                        errors={
                                            errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        }
                                    />
                                ))}
                            </div>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    Post opening balances
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-y-3">
                    <h2 className="font-medium">Already recorded</h2>

                    <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40 text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Entry
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        As at
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Memo
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Lines
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {recorded.map((run) => (
                                    <tr
                                        key={run.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-2 font-mono">
                                            {run.entry_number}
                                            {run.reversed_by !== null && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    Reversed by{' '}
                                                    {run.reversed_by}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 tabular-nums">
                                            {run.entry_date}
                                        </td>
                                        <td className="px-4 py-2 text-muted-foreground">
                                            {run.memo ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">
                                            {run.line_count}
                                        </td>
                                    </tr>
                                ))}

                                {recorded.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-4 py-10 text-center text-muted-foreground"
                                        >
                                            No opening balances posted yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

function BalanceRow({
    index,
    accounts,
    currencies,
    functionalCurrency,
    accountId,
    onAccountChange,
    onRemove,
    errors,
}: {
    index: number;
    accounts: Account[];
    currencies: Option[];
    functionalCurrency: string;
    accountId: string;
    onAccountChange: (accountId: string) => void;
    onRemove?: () => void;
    errors: Record<string, string | undefined>;
}) {
    const account = accounts.find(
        (candidate) => String(candidate.id) === accountId,
    );

    // A denominated account fixes its own currency; only an account that takes
    // any currency gets to be asked.
    const fixedCurrency = account?.currency_code ?? null;
    const [chosenCurrency, setChosenCurrency] = useState(functionalCurrency);
    const currency = fixedCurrency ?? chosenCurrency;
    const isForeign = currency !== functionalCurrency;

    const field = (name: string) => `lines.${index}.${name}`;

    return (
        <div className="grid gap-3 rounded-lg border p-3 sm:grid-cols-12">
            <div className="grid gap-2 sm:col-span-5">
                <Label htmlFor={field('account_id')}>Account</Label>
                <select
                    id={field('account_id')}
                    name={field('account_id')}
                    value={accountId}
                    onChange={(event) => onAccountChange(event.target.value)}
                    className={selectClasses}
                    required
                >
                    <option value="">Choose an account…</option>
                    {accounts.map((option) => (
                        <option key={option.id} value={option.id}>
                            {option.code} — {option.name}
                            {option.currency_code !== null
                                ? ` (${option.currency_code})`
                                : ''}
                        </option>
                    ))}
                </select>
                <InputError message={errors[field('account_id')]} />
            </div>

            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor={field('side')}>Side</Label>
                <select
                    id={field('side')}
                    name={field('side')}
                    // Preselected from the account's normal balance, and still
                    // the user's to change — a contra account carries its
                    // balance on the other side.
                    key={account?.normal_balance ?? 'none'}
                    defaultValue={account?.normal_balance ?? 'debit'}
                    className={selectClasses}
                >
                    <option value="debit">Debit</option>
                    <option value="credit">Credit</option>
                </select>
                <InputError message={errors[field('side')]} />
            </div>

            <div className="grid gap-2 sm:col-span-3">
                <Label htmlFor={field('amount')}>Amount</Label>
                <div className="flex gap-2">
                    {fixedCurrency !== null ? (
                        <>
                            <input
                                type="hidden"
                                name={field('currency_code')}
                                value={fixedCurrency}
                            />
                            <span className="flex h-9 items-center rounded-md border border-input px-2 font-mono text-xs text-muted-foreground">
                                {fixedCurrency}
                            </span>
                        </>
                    ) : (
                        <select
                            aria-label="Currency"
                            name={field('currency_code')}
                            value={chosenCurrency}
                            onChange={(event) =>
                                setChosenCurrency(event.target.value)
                            }
                            className={`${selectClasses} w-24`}
                        >
                            {currencies.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.value}
                                </option>
                            ))}
                        </select>
                    )}

                    <Input
                        id={field('amount')}
                        name={field('amount')}
                        inputMode="decimal"
                        placeholder="1500.00"
                        className="font-mono"
                        required
                    />
                </div>
                <InputError message={errors[field('amount')]} />
                <InputError message={errors[field('currency_code')]} />
            </div>

            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor={field('exchange_rate')}>Rate</Label>
                <Input
                    id={field('exchange_rate')}
                    name={field('exchange_rate')}
                    inputMode="decimal"
                    className="font-mono"
                    placeholder={isForeign ? '1650.25' : '—'}
                    disabled={!isForeign}
                    title={
                        isForeign
                            ? `The ${currency}/${functionalCurrency} rate this balance was struck at. Leave blank to use the recorded rate for the date.`
                            : `Already in ${functionalCurrency}; no conversion applies.`
                    }
                />
                <InputError message={errors[field('exchange_rate')]} />
            </div>

            {onRemove !== undefined && (
                <div className="sm:col-span-12">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onRemove}
                    >
                        <X className="size-4" />
                        Remove this line
                    </Button>
                </div>
            )}
        </div>
    );
}

OpeningBalancesCreate.layout = {
    breadcrumbs: [{ title: 'Opening Balances', href: create() }],
};
