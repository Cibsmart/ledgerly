<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One debit or credit of a journal entry.
 *
 * Amounts are signed with debit positive. There is no debit/credit column
 * pair: with a transaction amount and a translated functional amount per line,
 * a pair would mean four amount columns and four places a translation bug can
 * hide. Reports render debit and credit from the sign — see the accessors
 * below.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property string $currency_code
 * @property Money $amount_minor Signed, debit positive, transaction currency
 * @property string $exchange_rate
 * @property Money $functional_amount_minor Signed, functional currency
 * @property string|null $memo
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Money $debit
 * @property-read Money $credit
 * @property-read Money $functional_debit
 * @property-read Money $functional_credit
 */
#[Fillable([])]
class JournalLine extends Model
{
    /**
     * Deliberately no factory — lines are written by
     * App\Actions\Ledger\PostEntry and nothing else. See JournalEntry.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // The transaction amount carries its own currency, read from the
            // sibling column so an amount can never be stored without one.
            'amount_minor' => Money::class.':currency_code',

            // The translated amount is functional by definition, so it needs no
            // currency column and the cast takes no argument.
            'functional_amount_minor' => Money::class,

            'exchange_rate' => 'decimal:10',
        ];
    }

    protected static function booted(): void
    {
        // Lines are write-once. Unlike the entry header, there is no state in
        // which amending one is legitimate: a posted line is history, and an
        // unposted entry's lines are replaced by rewriting the draft, not by
        // editing rows underneath it.
        //
        // Phase 4 adds reconciliation columns (cleared_at, reconciliation_id),
        // which are metadata about a line rather than the line itself. When
        // they land, this guard needs an explicit allowlist of those columns —
        // widening it to "anything not an amount" would let a memo or an
        // account_id through with it.
        static::updating(function (self $line): void {
            throw new LogicException(
                "Journal line [{$line->id}] cannot be modified: journal lines are immutable. "
                .'Correct the entry with a reversing entry via App\Actions\Ledger\ReverseEntry.'
            );
        });

        static::deleting(function (self $line): void {
            throw new LogicException(
                "Journal line [{$line->id}] cannot be deleted: journal lines are immutable. "
                .'Correct the entry with a reversing entry via App\Actions\Ledger\ReverseEntry.'
            );
        });
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isDebit(): bool
    {
        return $this->functional_amount_minor->isPositive();
    }

    public function isCredit(): bool
    {
        return $this->functional_amount_minor->isNegative();
    }

    /**
     * The transaction-currency debit, or zero when this line is a credit.
     *
     * @return Attribute<Money, never>
     */
    protected function debit(): Attribute
    {
        return Attribute::get(fn (): Money => $this->sideOf($this->amount_minor, positive: true));
    }

    /**
     * @return Attribute<Money, never>
     */
    protected function credit(): Attribute
    {
        return Attribute::get(fn (): Money => $this->sideOf($this->amount_minor, positive: false));
    }

    /**
     * The functional-currency debit — what a trial balance actually sums, and
     * what expect()->toBalance() reads.
     *
     * @return Attribute<Money, never>
     */
    protected function functionalDebit(): Attribute
    {
        return Attribute::get(fn (): Money => $this->sideOf($this->functional_amount_minor, positive: true));
    }

    /**
     * @return Attribute<Money, never>
     */
    protected function functionalCredit(): Attribute
    {
        return Attribute::get(fn (): Money => $this->sideOf($this->functional_amount_minor, positive: false));
    }

    /**
     * Lines belonging to posted entries — the only ones that are ledger data.
     *
     * @param  Builder<self>  $query
     */
    public function scopePosted(Builder $query): void
    {
        $query->whereHas('journalEntry', function (Builder $entries): void {
            /** @var Builder<JournalEntry> $entries */
            $entries->posted();
        });
    }

    /**
     * One side of a signed amount, zero when the amount falls on the other.
     */
    private function sideOf(Money $amount, bool $positive): Money
    {
        if ($positive) {
            return $amount->isPositive() ? $amount : Money::zero($amount->currency);
        }

        return $amount->isNegative() ? $amount->negate() : Money::zero($amount->currency);
    }

    /**
     * The currency this line was transacted in.
     */
    public function currency(): Currency
    {
        return Currency::of($this->currency_code);
    }
}
