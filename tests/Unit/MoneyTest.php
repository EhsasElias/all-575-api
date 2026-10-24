<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_adds_subtracts_and_multiplies_in_whole_halalas(): void
    {
        $price = Money::halalas(575);

        $this->assertSame(1150, $price->plus($price)->halalas);
        $this->assertSame(0, $price->minus($price)->halalas);
        $this->assertSame(1725, $price->times(3)->halalas);
    }

    #[DataProvider('percentages')]
    public function test_basis_points_round_half_up(int $halalas, int $basisPoints, int $expected): void
    {
        $this->assertSame($expected, Money::halalas($halalas)->basisPoints($basisPoints)->halalas);
    }

    /** @return array<string, array{int, int, int}> */
    public static function percentages(): array
    {
        return [
            '15% of 100.00' => [10_000, 1500, 1500],
            '15% of 5.00' => [500, 1500, 75],
            'rounds .5 up' => [10, 500, 1],
            'rounds below .5 down' => [9, 500, 0],
            'zero' => [0, 1500, 0],
        ];
    }

    public function test_it_refuses_percentages_of_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::halalas(-1)->basisPoints(1500);
    }

    public function test_allocation_never_loses_or_creates_a_halala(): void
    {
        $shares = Money::halalas(100)->allocate([1, 1, 1]);

        $this->assertSame([34, 33, 33], array_map(fn (Money $m): int => $m->halalas, $shares));
        $this->assertSame(100, array_sum(array_map(fn (Money $m): int => $m->halalas, $shares)));
    }

    public function test_allocation_follows_the_weights(): void
    {
        $shares = Money::halalas(1000)->allocate([575, 1150]);

        $this->assertSame([333, 667], array_map(fn (Money $m): int => $m->halalas, $shares));
    }

    public function test_allocation_gives_nothing_to_a_zero_weight(): void
    {
        $shares = Money::halalas(500)->allocate([0, 1]);

        $this->assertSame([0, 500], array_map(fn (Money $m): int => $m->halalas, $shares));
    }

    public function test_allocation_needs_a_positive_total_weight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::halalas(500)->allocate([0, 0]);
    }

    public function test_it_serialises_to_an_integer(): void
    {
        $this->assertSame('{"price":575}', json_encode(['price' => Money::halalas(575)]));
    }
}
