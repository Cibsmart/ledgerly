<?php

namespace App\Models;

use App\Support\Currency;
use Carbon\CarbonInterface;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bookkeeper-maintained exchange rate, effective-dated.
 *
 * **This table is a default, not the authority (D8).** PostEntry accepts an
 * explicit rate per line and only falls back here when none is given — a
 * USD→NGN conversion inside Grey executes at a specific negotiated rate, and
 * that rate, not the day's table entry, is what gets snapshotted onto the line.
 *
 * A posted line never reads this table again. Correcting a historical rate
 * changes what future postings default to and nothing about the past, which is
 * invariant 2 applied to FX.
 *
 * @property int $id
 * @property string $base_code
 * @property string $quote_code
 * @property string $rate
 * @property CarbonInterface $effective_on
 * @property string|null $source
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['base_code', 'quote_code', 'rate', 'effective_on', 'source', 'created_by'])]
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Read as a string, never a float: the rate goes straight into
            // bcmath in Money::convertTo(), and a float round-trip is exactly
            // the precision loss integer minor units exist to avoid.
            'rate' => 'decimal:10',
            'effective_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The rate in force for a currency pair on a given date, or null when the
     * table has nothing on or before it.
     *
     * Effective dating means "applies from this date until superseded", so this
     * takes the latest row at or before the date rather than an exact match —
     * rates are not entered every day.
     */
    public static function resolve(Currency $base, Currency $quote, CarbonInterface $on): ?self
    {
        return self::query()
            ->forPair($base, $quote)
            ->effectiveOn($on)
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForPair(Builder $query, Currency $base, Currency $quote): void
    {
        $query->where('base_code', $base->code)->where('quote_code', $quote->code);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $on): void
    {
        $query->whereDate('effective_on', '<=', $on->toDateString())
            ->orderByDesc('effective_on')
            ->orderByDesc('id');
    }
}
