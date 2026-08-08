<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\CashFlowClassification;
use App\Enums\NormalBalance;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A line in the chart of accounts.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property int|null $parent_id
 * @property string|null $currency_code
 * @property bool $is_monetary
 * @property CashFlowClassification|null $cash_flow_classification
 * @property bool $is_cash_equivalent
 * @property bool $is_active
 * @property string|null $description
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'code',
    'name',
    'type',
    'parent_id',
    'currency_code',
    'is_monetary',
    'cash_flow_classification',
    'is_cash_equivalent',
    'is_active',
    'description',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'cash_flow_classification' => CashFlowClassification::class,
            'is_monetary' => 'boolean',
            'is_cash_equivalent' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Accounts are archivable, never deletable once any line references
        // them. Deleting one would orphan history, and the restrictOnDelete
        // foreign key would reject it anyway — this turns a constraint
        // violation into a sentence that says what to do instead.
        static::deleting(function (self $account): void {
            if ($account->lines()->exists()) {
                throw new LogicException(
                    "Account [{$account->code} {$account->name}] has journal lines and cannot be deleted. "
                    .'Set is_active to false to archive it instead.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Whether this account may be posted to directly.
     *
     * An account with children is a header, and posting to it would
     * double-count against its own children in any report that sums a subtree.
     * Derived from the tree rather than stored in an `is_postable` column, so
     * the rule cannot drift out of step with the tree it describes. PostEntry
     * enforces it.
     */
    public function isPostable(): bool
    {
        return ! $this->hasChildren();
    }

    /**
     * Reads an eager-loaded count or relation where one is present, so callers
     * that resolve many accounts at once — PostEntry, the chart screen — do not
     * fire a query per account.
     */
    public function hasChildren(): bool
    {
        if (array_key_exists('children_count', $this->attributes)) {
            return (int) $this->attributes['children_count'] > 0;
        }

        if ($this->relationLoaded('children')) {
            return $this->children->isNotEmpty();
        }

        return $this->children()->exists();
    }

    /**
     * The side this account's balance naturally falls on — a function of its
     * type, never a stored column.
     */
    public function normalBalance(): NormalBalance
    {
        return $this->type->normalBalance();
    }

    /**
     * The currency this account is denominated in, or null where it accepts
     * any currency.
     */
    public function currency(): ?Currency
    {
        return $this->currency_code === null ? null : Currency::of($this->currency_code);
    }

    /**
     * Whether a line in the given currency may be posted here.
     */
    public function accepts(Currency $currency): bool
    {
        $denomination = $this->currency();

        return $denomination === null || $denomination->equals($currency);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Accounts that can be posted to: active leaves of the tree.
     *
     * @param  Builder<self>  $query
     */
    public function scopePostable(Builder $query): void
    {
        $query->active()->whereDoesntHave('children');
    }

    /**
     * The chart's own order, which every report reads it in.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInChartOrder(Builder $query): void
    {
        $query->orderBy('code');
    }
}
