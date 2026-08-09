import { Form, Head } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ExchangeRateController from '@/actions/App/Http/Controllers/ExchangeRateController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { index } from '@/routes/exchange-rates';

type Option = { value: string; label: string };

type Rate = {
    id: number;
    base_code: string;
    quote_code: string;
    rate: string;
    rate_display: string;
    effective_on: string;
    source: string | null;
    recorded_by: string | null;
};

type PageProps = {
    rates: Rate[];
    functionalCurrency: string;
    options: { currencies: Option[] };
    can: { manage: boolean };
};

const selectClasses =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

export default function ExchangeRatesIndex({
    rates,
    functionalCurrency,
    options,
    can,
}: PageProps) {
    const [editing, setEditing] = useState<Rate | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Exchange Rates" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Exchange Rates"
                        description="A rate applies from its effective date until superseded. These are the default used when a posting quotes no rate of its own — the rate an entry was actually translated at is stored on the entry."
                    />

                    {can.manage && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-4" />
                            Record a rate
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">Pair</th>
                                <th className="px-4 py-2 text-right font-medium">
                                    Rate
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Effective from
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Source
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Recorded by
                                </th>
                                <th className="px-4 py-2 text-right font-medium">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rates.map((rate) => (
                                <tr
                                    key={rate.id}
                                    className="border-b last:border-0 hover:bg-muted/30"
                                >
                                    <td className="px-4 py-2 font-mono">
                                        {rate.base_code}/{rate.quote_code}
                                    </td>
                                    <td className="px-4 py-2 text-right font-mono tabular-nums">
                                        {rate.rate_display}
                                    </td>
                                    <td className="px-4 py-2 tabular-nums">
                                        {rate.effective_on}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {rate.source ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        {rate.recorded_by ?? '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {can.manage && (
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(rate)
                                                    }
                                                >
                                                    <Pencil className="size-4" />
                                                    <span className="sr-only">
                                                        Edit the{' '}
                                                        {rate.base_code}/
                                                        {rate.quote_code} rate
                                                        effective{' '}
                                                        {rate.effective_on}
                                                    </span>
                                                </Button>

                                                <Form
                                                    {...ExchangeRateController.destroy.form(
                                                        rate.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        type="submit"
                                                    >
                                                        <Trash2 className="size-4" />
                                                        <span className="sr-only">
                                                            Delete the{' '}
                                                            {rate.base_code}/
                                                            {rate.quote_code}{' '}
                                                            rate effective{' '}
                                                            {rate.effective_on}
                                                        </span>
                                                    </Button>
                                                </Form>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}

                            {rates.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-10 text-center text-muted-foreground"
                                    >
                                        No rates recorded yet. A
                                        foreign-currency entry cannot be posted
                                        without either a rate here or a rate
                                        quoted on the entry itself.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {creating && (
                <RateDialog
                    onClose={() => setCreating(false)}
                    functionalCurrency={functionalCurrency}
                    options={options}
                />
            )}

            {editing !== null && (
                <RateDialog
                    key={editing.id}
                    onClose={() => setEditing(null)}
                    rate={editing}
                    functionalCurrency={functionalCurrency}
                    options={options}
                />
            )}
        </>
    );
}

function RateDialog({
    onClose,
    rate,
    functionalCurrency,
    options,
}: {
    onClose: () => void;
    rate?: Rate;
    functionalCurrency: string;
    options: { currencies: Option[] };
}) {
    const isEdit = rate !== undefined;

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit
                            ? `Correct ${rate.base_code}/${rate.quote_code}`
                            : 'Record a rate'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Correcting a rate changes what future postings default to. Entries already posted carry the rate they were translated at and do not move.'
                            : 'How many units of the quote currency one unit of the base currency buys. The rate applies from its effective date until a later one supersedes it.'}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...(isEdit
                        ? ExchangeRateController.update.form(rate.id)
                        : ExchangeRateController.store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {/* The pair is fixed once recorded — a different
                                pair is a different rate, not a correction. */}
                            {!isEdit && (
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="base_code">Base</Label>
                                        <select
                                            id="base_code"
                                            name="base_code"
                                            defaultValue="USD"
                                            className={selectClasses}
                                        >
                                            {options.currencies.map(
                                                (currency) => (
                                                    <option
                                                        key={currency.value}
                                                        value={currency.value}
                                                    >
                                                        {currency.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={errors.base_code}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="quote_code">
                                            Quote
                                        </Label>
                                        <select
                                            id="quote_code"
                                            name="quote_code"
                                            defaultValue={functionalCurrency}
                                            className={selectClasses}
                                        >
                                            {options.currencies.map(
                                                (currency) => (
                                                    <option
                                                        key={currency.value}
                                                        value={currency.value}
                                                    >
                                                        {currency.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <p className="text-xs text-muted-foreground">
                                            Postings translate into{' '}
                                            {functionalCurrency}, so that is the
                                            quote currency the ledger looks up.
                                        </p>
                                        <InputError
                                            message={errors.quote_code}
                                        />
                                    </div>
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="rate">Rate</Label>
                                <Input
                                    id="rate"
                                    name="rate"
                                    defaultValue={rate?.rate}
                                    required
                                    inputMode="decimal"
                                    placeholder="1650.25"
                                    className="font-mono"
                                />
                                <p className="text-xs text-muted-foreground">
                                    A plain decimal, up to ten decimal places.
                                </p>
                                <InputError message={errors.rate} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="effective_on">
                                    Effective from
                                </Label>
                                <Input
                                    id="effective_on"
                                    name="effective_on"
                                    type="date"
                                    defaultValue={rate?.effective_on}
                                    required
                                />
                                <InputError message={errors.effective_on} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="source">Source</Label>
                                <Input
                                    id="source"
                                    name="source"
                                    defaultValue={rate?.source ?? ''}
                                    placeholder="CBN official window"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Optional, but worth filling in: it is what
                                    explains the number to whoever reads the
                                    books later.
                                </p>
                                <InputError message={errors.source} />
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
                                    {isEdit ? 'Save correction' : 'Record rate'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

ExchangeRatesIndex.layout = {
    breadcrumbs: [{ title: 'Exchange Rates', href: index() }],
};
