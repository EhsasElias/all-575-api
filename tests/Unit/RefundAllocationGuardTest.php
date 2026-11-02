<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Finance\RefundAllocationGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RefundAllocationGuardTest extends TestCase
{
    public function test_accepts_partial_allocation(): void
    {
        (new RefundAllocationGuard())->validate(575, [200, 100]);
        self::assertTrue(true);
    }

    public function test_accepts_full_allocation(): void
    {
        (new RefundAllocationGuard())->validate(575, [300, 275]);
        self::assertTrue(true);
    }

    public function test_rejects_total_exceeding_refund(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RefundAllocationGuard())->validate(575, [400, 200]);
    }

    public function test_rejects_zero_allocation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RefundAllocationGuard())->validate(575, [0]);
    }

    public function test_rejects_empty_allocations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RefundAllocationGuard())->validate(575, []);
    }
}
