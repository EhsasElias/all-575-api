<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of Saudi riyals held as an integer number of halalas (100 halalas = 1 SAR).
 *
 * Money is never held as a float: 5.75 SAR is Money::halalas(575).
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(public int $halalas) {}

    public static function halalas(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** The amount as a plain integer number of halalas, for building responses. */
    public function toInt(): int
    {
        return $this->halalas;
    }

    public function plus(self $other): self
    {
        return new self($this->halalas + $other->halalas);
    }

    public function minus(self $other): self
    {
        return new self($this->halalas - $other->halalas);
    }

    public function times(int $factor): self
    {
        return new self($this->halalas * $factor);
    }

    /** Rounds half up. Only defined for non-negative amounts. */
    public function basisPoints(int $basisPoints): self
    {
        if ($this->halalas < 0 || $basisPoints < 0) {
            throw new InvalidArgumentException('Percentages apply to non-negative amounts only.');
        }

        return new self(intdiv($this->halalas * $basisPoints * 2 + 10_000, 20_000));
    }

    public function min(self $other): self
    {
        return $this->halalas <= $other->halalas ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->halalas === 0;
    }

    public function isNegative(): bool
    {
        return $this->halalas < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->halalas > $other->halalas;
    }

    public function equals(self $other): bool
    {
        return $this->halalas === $other->halalas;
    }

    /**
     * Splits this amount in proportion to the weights without losing or creating a halala.
     * Leftover halalas go to the largest fractional remainders (ties: earlier index first).
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        $totalWeight = array_sum($weights);

        if ($this->halalas < 0 || $weights === [] || $totalWeight <= 0 || min($weights) < 0) {
            throw new InvalidArgumentException('Cannot allocate: need non-negative weights with a positive sum.');
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $product = $this->halalas * $weight;
            $shares[$index] = intdiv($product, $totalWeight);
            $remainders[$index] = $product % $totalWeight;
        }

        $leftover = $this->halalas - array_sum($shares);
        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach (array_slice($order, 0, $leftover) as $index) {
            $shares[$index]++;
        }

        return array_map(fn (int $halalas): self => new self($halalas), $shares);
    }

    public function jsonSerialize(): int
    {
        return $this->halalas;
    }
}
