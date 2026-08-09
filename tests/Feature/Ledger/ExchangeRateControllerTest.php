<?php

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Ledger\PendingLine;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->bookkeeper = User::factory()->bookkeeper()->create();
});

describe('index', function () {
    it('requires authentication', function () {
        $this->get(route('exchange-rates.index'))->assertRedirect(route('login'));
    });

    it('lists recorded rates', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->at('1650.2500000000')->effectiveOn('2026-01-01')->create();

        $rates = collect(
            $this->actingAs($this->bookkeeper)
                ->get(route('exchange-rates.index'))
                ->viewData('page')['props']['rates']
        );

        expect($rates)->toHaveCount(1)
            ->and($rates->first()['base_code'])->toBe('USD')
            ->and($rates->first()['effective_on'])->toBe('2026-01-01')
            // The stored value keeps its ten decimal places; only the display
            // string is trimmed.
            ->and($rates->first()['rate_display'])->toBe('1650.25')
            ->and($rates->first()['rate'])->toBe('1650.2500000000');
    });

    it('lets a viewer read rates but not manage them', function () {
        $viewer = User::factory()->viewer()->create();

        $props = $this->actingAs($viewer)
            ->get(route('exchange-rates.index'))
            ->assertOk()
            ->viewData('page')['props'];

        expect($props['can']['manage'])->toBeFalse();
    });
});

describe('store', function () {
    it('records a rate', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('exchange-rates.store'), [
                'base_code' => 'USD',
                'quote_code' => 'NGN',
                'rate' => '1650.25',
                'effective_on' => '2026-02-01',
                'source' => 'CBN official window',
            ])
            ->assertRedirect(route('exchange-rates.index'));

        $rate = ExchangeRate::sole();

        expect($rate->base_code)->toBe('USD')
            ->and($rate->quote_code)->toBe('NGN')
            ->and((float) $rate->rate)->toBe(1650.25)
            ->and($rate->effective_on->toDateString())->toBe('2026-02-01')
            ->and($rate->created_by)->toBe($this->bookkeeper->id);
    });

    it('records an audit entry naming the pair', function () {
        $this->actingAs($this->bookkeeper)->post(route('exchange-rates.store'), [
            'base_code' => 'USD',
            'quote_code' => 'NGN',
            'rate' => '1650.25',
            'effective_on' => '2026-02-01',
        ]);

        $activity = Activity::query()->where('log_name', 'exchange-rates')->sole();

        expect($activity->event)->toBe('created')
            ->and($activity->properties['pair'])->toBe('USD/NGN')
            ->and($activity->properties['rate'])->toBe('1650.25')
            ->and($activity->causer_id)->toBe($this->bookkeeper->id);
    });

    it('rejects a second rate for the same pair on the same date', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->effectiveOn('2026-02-01')->create();

        $this->actingAs($this->bookkeeper)
            ->post(route('exchange-rates.store'), [
                'base_code' => 'USD',
                'quote_code' => 'NGN',
                'rate' => '1700',
                'effective_on' => '2026-02-01',
            ])
            ->assertSessionHasErrors('effective_on');

        expect(ExchangeRate::count())->toBe(1);
    });

    it('allows the same date for a different pair', function () {
        ExchangeRate::factory()->pair('USD', 'NGN')->effectiveOn('2026-02-01')->create();

        $this->actingAs($this->bookkeeper)
            ->post(route('exchange-rates.store'), [
                'base_code' => 'GBP',
                'quote_code' => 'NGN',
                'rate' => '2100',
                'effective_on' => '2026-02-01',
            ])
            ->assertSessionHasNoErrors();

        expect(ExchangeRate::count())->toBe(2);
    });

    it('rejects a currency against itself', function () {
        $this->actingAs($this->bookkeeper)
            ->post(route('exchange-rates.store'), [
                'base_code' => 'NGN',
                'quote_code' => 'NGN',
                'rate' => '1',
                'effective_on' => '2026-02-01',
            ])
            ->assertSessionHasErrors('quote_code');
    });

    // The rate is handed straight to bcmath. Anything that is not a plain
    // decimal is a value whose meaning depends on how it is parsed, which is
    // exactly what integer minor units exist to avoid.
    it('rejects a rate that is not a plain positive decimal', function (string $rate) {
        $this->actingAs($this->bookkeeper)
            ->post(route('exchange-rates.store'), [
                'base_code' => 'USD',
                'quote_code' => 'NGN',
                'rate' => $rate,
                'effective_on' => '2026-02-01',
            ])
            ->assertSessionHasErrors('rate');

        expect(ExchangeRate::count())->toBe(0);
    })->with([
        'scientific notation' => '1.65e3',
        'negative' => '-1650',
        'zero' => '0',
        'hexadecimal' => '0x1A',
        'too many decimal places' => '1650.12345678901',
        'not a number' => 'sixteen fifty',
    ]);

    it('refuses a viewer', function () {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)
            ->post(route('exchange-rates.store'), [
                'base_code' => 'USD',
                'quote_code' => 'NGN',
                'rate' => '1650',
                'effective_on' => '2026-02-01',
            ])
            ->assertForbidden();

        expect(ExchangeRate::count())->toBe(0);
    });
});

describe('update', function () {
    it('corrects a rate', function () {
        $rate = ExchangeRate::factory()->at('1600.0000000000')->create();

        $this->actingAs($this->bookkeeper)
            ->patch(route('exchange-rates.update', $rate), [
                'rate' => '1655.75',
                'effective_on' => '2026-01-01',
                'source' => 'Corrected from the bank advice',
            ])
            ->assertRedirect(route('exchange-rates.index'));

        expect((float) $rate->refresh()->rate)->toBe(1655.75)
            ->and($rate->source)->toBe('Corrected from the bank advice');
    });

    /**
     * Invariant 2 applied to FX. This is the property that makes correcting a
     * rate safe at all: the line snapshotted its rate at post time and never
     * reads this table again, so history cannot move underneath a report.
     */
    it('does not move an entry already posted at the old rate', function () {
        fiscalYear(2026);

        $usdCash = Account::factory()->cash('USD')->create(['code' => '1015']);
        $revenue = Account::factory()->revenue()->create(['code' => '4010']);

        $rate = ExchangeRate::factory()->pair('USD', 'NGN')->at('1600.0000000000')
            ->effectiveOn('2026-01-01')->create();

        $entry = postEntry([
            PendingLine::debit($usdCash, money(1_000, 'USD')),
            PendingLine::credit($revenue, money(1_600_000)),
        ], '2026-01-15');

        $snapshot = fn (JournalEntry $entry): array => $entry->lines
            ->map(fn ($line): array => [
                'rate' => $line->exchange_rate,
                'functional_minor' => $line->functional_amount_minor->minor,
            ])
            ->all();

        $before = $snapshot($entry);

        $this->actingAs($this->bookkeeper)->patch(route('exchange-rates.update', $rate), [
            'rate' => '1900',
            'effective_on' => '2026-01-01',
        ]);

        expect($snapshot($entry->fresh()))->toBe($before)
            ->and($before[0]['rate'])->toBe('1600.0000000000')
            ->and($entry->fresh()->lines)->toBalance();
    });

    it('refuses to re-point a rate at a different pair', function () {
        $rate = ExchangeRate::factory()->pair('USD', 'NGN')->create();

        $this->actingAs($this->bookkeeper)
            ->patch(route('exchange-rates.update', $rate), [
                'base_code' => 'GBP',
                'quote_code' => 'NGN',
                'rate' => '1700',
                'effective_on' => '2026-01-01',
            ])
            ->assertSessionHasErrors('base_code');

        expect($rate->refresh()->base_code)->toBe('USD');
    });

    it('keeps the before and after in the audit trail', function () {
        $rate = ExchangeRate::factory()->at('1600.0000000000')->create();

        $this->actingAs($this->bookkeeper)->patch(route('exchange-rates.update', $rate), [
            'rate' => '1655.75',
            'effective_on' => '2026-01-01',
        ]);

        $activity = Activity::query()->where('event', 'updated')->sole();

        expect($activity->properties['before']['rate'])->toBe('1600.0000000000')
            ->and($activity->properties['after']['rate'])->toBe('1655.75');
    });

    it('refuses a viewer', function () {
        $rate = ExchangeRate::factory()->at('1600.0000000000')->create();

        $this->actingAs(User::factory()->viewer()->create())
            ->patch(route('exchange-rates.update', $rate), [
                'rate' => '9999',
                'effective_on' => '2026-01-01',
            ])
            ->assertForbidden();

        expect((float) $rate->refresh()->rate)->toBe(1600.0);
    });
});

describe('destroy', function () {
    it('deletes a rate and audits what it was', function () {
        $rate = ExchangeRate::factory()->pair('USD', 'NGN')->at('1600.0000000000')
            ->effectiveOn('2026-01-01')->create();

        $this->actingAs($this->bookkeeper)
            ->delete(route('exchange-rates.destroy', $rate))
            ->assertRedirect(route('exchange-rates.index'));

        expect(ExchangeRate::count())->toBe(0);

        // The trail is now the only record the rate ever existed, so it has to
        // carry the whole row.
        $activity = Activity::query()->where('event', 'deleted')->sole();

        expect($activity->properties['pair'])->toBe('USD/NGN')
            ->and($activity->properties['rate'])->toBe('1600.0000000000')
            ->and($activity->properties['effective_on'])->toBe('2026-01-01');
    });

    it('leaves entries posted from it untouched', function () {
        fiscalYear(2026);

        $usdCash = Account::factory()->cash('USD')->create(['code' => '1015']);
        $revenue = Account::factory()->revenue()->create(['code' => '4010']);

        $rate = ExchangeRate::factory()->pair('USD', 'NGN')->at('1600.0000000000')
            ->effectiveOn('2026-01-01')->create();

        $entry = postEntry([
            PendingLine::debit($usdCash, money(1_000, 'USD')),
            PendingLine::credit($revenue, money(1_600_000)),
        ], '2026-01-15');

        $this->actingAs($this->bookkeeper)->delete(route('exchange-rates.destroy', $rate));

        expect($entry->fresh()->lines)->toHaveCount(2)->toBalance();
    });

    it('refuses a viewer', function () {
        $rate = ExchangeRate::factory()->create();

        $this->actingAs(User::factory()->viewer()->create())
            ->delete(route('exchange-rates.destroy', $rate))
            ->assertForbidden();

        expect(ExchangeRate::count())->toBe(1);
    });
});
