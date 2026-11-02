<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Finance\CommissionCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommissionCalculatorTest extends TestCase
{
    public static function commissionExamples(): array
    {
        return [
            'zero rate' => [575, 0, 0],
            'full rate' => [575, 10000, 575],
            'ten percent' => [575, 1000, 58],
            'small amount rounded down' => [1, 1000, 0],
            'half halala rounded up' => [5, 1000, 1],
            'large amount' => [100000, 2500, 25000],
        ];
    }

    #[DataProvider('commissionExamples')]
    public function test_commission_is_calculated_in_integer_halalas(int $amount, int $rate, int $expected): void
    {
        self::assertSame($expected, (new CommissionCalculator())->calculate($amount, $rate));
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommissionCalculator())->calculate(-1, 1000);
    }

    public function test_rate_above_full_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommissionCalculator())->calculate(575, 10001);
    }
}
