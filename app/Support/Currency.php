<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A currency the ledger can hold amounts in, resolved from `config/money.php`.
 *
 * This is a value object rather than a backed enum because scale is data, not
 * behaviour: currencies differ in how many minor units they subdivide into
 * (JPY none, NGN two, KWD three), and the supported set is deployment
 * configuration rather than a property of the codebase.
 */
final readonly class Currency implements JsonSerializable, Stringable
{
    public function __construct(
        public string $code,
        public string $symbol,
        public int $scale,
        public string $name,
    ) {}

    /**
     * Resolve a configured currency by its ISO 4217 code.
     */
    public static function of(string $code): self
    {
        $code = strtoupper(trim($code));

        /** @var array<string, array{symbol: string, scale: int, name: string}> $configured */
        $configured = config('money.currencies', []);

        if (! array_key_exists($code, $configured)) {
            throw new InvalidArgumentException(
                "Unsupported currency [{$code}]. Add it to config/money.php before using it."
            );
        }

        return new self(
            code: $code,
            symbol: $configured[$code]['symbol'],
            scale: $configured[$code]['scale'],
            name: $configured[$code]['name'],
        );
    }

    /**
     * The currency the books are kept in, and the only one a trial balance
     * can net to zero in.
     */
    public static function functional(): self
    {
        return self::of((string) config('money.functional', 'NGN'));
    }

    /**
     * Every configured currency, keyed by code.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        /** @var array<string, array{symbol: string, scale: int, name: string}> $configured */
        $configured = config('money.currencies', []);

        $currencies = [];

        foreach (array_keys($configured) as $code) {
            $currencies[$code] = self::of($code);
        }

        return $currencies;
    }

    public function isFunctional(): bool
    {
        return $this->equals(self::functional());
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    /**
     * Minor units per major unit — 100 at scale 2, 1 at scale 0.
     */
    public function factor(): int
    {
        return 10 ** $this->scale;
    }

    public function __toString(): string
    {
        return $this->code;
    }

    /**
     * @return array{code: string, symbol: string, scale: int, name: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'symbol' => $this->symbol,
            'scale' => $this->scale,
            'name' => $this->name,
        ];
    }
}
