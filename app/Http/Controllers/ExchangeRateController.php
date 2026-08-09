<?php

namespace App\Http\Controllers;

use App\Actions\Ledger\DeleteExchangeRate;
use App\Actions\Ledger\RecordExchangeRate;
use App\Actions\Ledger\UpdateExchangeRate;
use App\Http\Requests\ExchangeRates\ExchangeRateStoreRequest;
use App\Http\Requests\ExchangeRates\ExchangeRateUpdateRequest;
use App\Models\ExchangeRate;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The exchange rate screen.
 *
 * Nothing here re-derives anything about a posted entry. This table supplies
 * the default rate a *future* posting translates at; the rate a posted line
 * actually used is on the line.
 */
class ExchangeRateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ExchangeRate::class);

        $functional = Currency::functional();

        $rates = ExchangeRate::query()
            ->with('createdBy:id,name')
            ->orderBy('base_code')
            ->orderBy('quote_code')
            ->orderByDesc('effective_on')
            ->get()
            ->map(fn (ExchangeRate $rate): array => [
                'id' => $rate->id,
                'base_code' => $rate->base_code,
                'quote_code' => $rate->quote_code,
                // Trimmed for display only. The stored value keeps its ten
                // decimal places, and the edit form is handed that raw value.
                'rate' => $rate->rate,
                'rate_display' => $this->trimZeros($rate->rate),
                'effective_on' => $rate->effective_on->toDateString(),
                'source' => $rate->source,
                'recorded_by' => $rate->createdBy?->name,
            ])
            ->values()
            ->all();

        return Inertia::render('exchange-rates/index', [
            'rates' => $rates,
            'functionalCurrency' => $functional->code,
            'options' => [
                'currencies' => array_map(
                    fn (Currency $currency): array => [
                        'value' => $currency->code,
                        'label' => "{$currency->code} — {$currency->name}",
                    ],
                    array_values(Currency::all()),
                ),
            ],
            'can' => [
                'manage' => $request->user()?->can('create', ExchangeRate::class) ?? false,
            ],
        ]);
    }

    public function store(ExchangeRateStoreRequest $request, RecordExchangeRate $recordRate): RedirectResponse
    {
        $this->authorize('create', ExchangeRate::class);

        $validated = $request->validated();

        $rate = $recordRate->handle(
            base: Currency::of((string) $validated['base_code']),
            quote: Currency::of((string) $validated['quote_code']),
            rate: (string) $validated['rate'],
            effectiveOn: (string) $validated['effective_on'],
            source: $validated['source'] ?? null,
            user: $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Recorded :pair rate effective :date.', [
                'pair' => "{$rate->base_code}/{$rate->quote_code}",
                'date' => $rate->effective_on->toDateString(),
            ]),
        ]);

        return to_route('exchange-rates.index');
    }

    public function update(
        ExchangeRateUpdateRequest $request,
        ExchangeRate $exchangeRate,
        UpdateExchangeRate $updateRate,
    ): RedirectResponse {
        $this->authorize('update', $exchangeRate);

        $validated = $request->validated();

        $updateRate->handle(
            exchangeRate: $exchangeRate,
            rate: (string) $validated['rate'],
            effectiveOn: (string) $validated['effective_on'],
            source: $validated['source'] ?? null,
            user: $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Corrected :pair rate. Entries already posted are unaffected.', [
                'pair' => "{$exchangeRate->base_code}/{$exchangeRate->quote_code}",
            ]),
        ]);

        return to_route('exchange-rates.index');
    }

    public function destroy(
        Request $request,
        ExchangeRate $exchangeRate,
        DeleteExchangeRate $deleteRate,
    ): RedirectResponse {
        $this->authorize('delete', $exchangeRate);

        $pair = "{$exchangeRate->base_code}/{$exchangeRate->quote_code}";

        $deleteRate->handle($exchangeRate, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Deleted the :pair rate.', ['pair' => $pair]),
        ]);

        return to_route('exchange-rates.index');
    }

    /**
     * "1650.2500000000" reads as "1650.25" without losing anything: the stored
     * value is what postings use, and this string is only ever displayed.
     */
    private function trimZeros(string $rate): string
    {
        return str_contains($rate, '.')
            ? rtrim(rtrim($rate, '0'), '.')
            : $rate;
    }
}
