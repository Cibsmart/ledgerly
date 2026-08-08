<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * The header of a double-entry transaction.
 *
 * @property int $id
 * @property string $entry_number
 * @property CarbonInterface $entry_date
 * @property int $period_id
 * @property string|null $memo
 * @property string|null $reference
 * @property JournalEntryStatus $status
 * @property int|null $created_by
 * @property int|null $posted_by
 * @property CarbonInterface|null $posted_at
 * @property int|null $reverses_journal_entry_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([])]
class JournalEntry extends Model
{
    /**
     * Deliberately no factory.
     *
     * Entries are created by App\Actions\Ledger\PostEntry and nothing else, so
     * that balance validation, period resolution and attribution cannot be
     * bypassed. Tests build entries through the `postEntry()` helper in
     * tests/Pest.php, which calls the action.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'status' => JournalEntryStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Invariant 2, enforced in the model as well as in the write path.
        // PostEntry already refuses to touch a posted entry; this layer is what
        // makes the invariant survive a seeder, a console command, or a tinker
        // session. Neither layer is redundant — one of them is the only thing
        // standing between a careless script and a rewritten audit trail.
        //
        // The check reads the *original* status so that the draft → posted
        // transition itself remains possible; what is forbidden is changing an
        // entry that was already posted when it was loaded.
        static::updating(function (self $entry): void {
            if ($entry->wasPostedWhenLoaded()) {
                throw new LogicException(
                    "Journal entry [{$entry->entry_number}] is posted and cannot be modified. "
                    .'Correct it with a reversing entry via App\Actions\Ledger\ReverseEntry.'
                );
            }
        });

        static::deleting(function (self $entry): void {
            if ($entry->wasPostedWhenLoaded()) {
                throw new LogicException(
                    "Journal entry [{$entry->entry_number}] is posted and cannot be deleted. "
                    .'Correct it with a reversing entry via App\Actions\Ledger\ReverseEntry.'
                );
            }
        });
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The entry this one reverses.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_entry_id');
    }

    /**
     * The entry that reverses this one, if any.
     *
     * There is no `reversed` status: whether an entry has been reversed is
     * exactly whether this relation exists. The column is unique, so it is
     * at most one.
     *
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_journal_entry_id');
    }

    /**
     * What produced this entry — a payroll run, an import, a transfer.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPosted(): bool
    {
        return $this->status === JournalEntryStatus::Posted;
    }

    public function isDraft(): bool
    {
        return $this->status === JournalEntryStatus::Draft;
    }

    public function isReversal(): bool
    {
        return $this->reverses_journal_entry_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->relationLoaded('reversal')
            ? $this->reversal !== null
            : $this->reversal()->exists();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', JournalEntryStatus::Posted);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOnOrBefore(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('entry_date', '<=', $date->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeBetweenDates(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString());
    }

    /**
     * Whether this record was already posted in the database, as opposed to
     * being posted by the change currently in flight.
     */
    private function wasPostedWhenLoaded(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->getRawOriginal('status') === JournalEntryStatus::Posted->value;
    }
}
