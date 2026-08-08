<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;

/**
 * A fiscal period. Entries are assigned to one by their entry date, and a
 * closed period accepts no further postings (invariant 6).
 *
 * @property int $id
 * @property int $fiscal_year
 * @property string $name
 * @property CarbonInterface $start_date
 * @property CarbonInterface $end_date
 * @property CarbonInterface|null $closed_at
 * @property int|null $closed_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['fiscal_year', 'name', 'start_date', 'end_date'])]
class Period extends Model
{
    /** @use HasFactory<PeriodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function contains(CarbonInterface $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /**
     * The period an entry dated `$date` belongs to.
     *
     * Bounds are inclusive and periods are non-overlapping, so this matches at
     * most one row.
     *
     * @param  Builder<self>  $query
     */
    public function scopeContaining(Builder $query, CarbonInterface $date): void
    {
        $formatted = $date->toDateString();

        $query->whereDate('start_date', '<=', $formatted)
            ->whereDate('end_date', '>=', $formatted);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Periods that overlap the given span — the check that keeps them
     * non-overlapping when a new one is created.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString());
    }

    /**
     * A calendar-month period, the shape the seeder and the fixtures use.
     */
    public static function monthOf(CarbonInterface $date): self
    {
        $start = Date::instance($date)->startOfMonth();

        return new self([
            'fiscal_year' => (int) $start->format('Y'),
            'name' => $start->format('F Y'),
            'start_date' => $start,
            'end_date' => $start->endOfMonth(),
        ]);
    }
}
