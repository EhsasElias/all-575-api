<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Finance\RefundAllocationFingerprint;
use PHPUnit\Framework\TestCase;

final class RefundAllocationFingerprintTest extends TestCase
{
    public function test_order_of_rows_does_not_change_fingerprint(): void
    {
        $fingerprint = new RefundAllocationFingerprint();
        $one = [
            ['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200],
            ['suborder_id' => 'b', 'seller_id' => 2, 'amount_halalas' => 375],
        ];
        self::assertSame($fingerprint->hash('refund-1', $one), $fingerprint->hash('refund-1', array_reverse($one)));
    }

    public function test_amount_change_invalidates_fingerprint(): void
    {
        $fingerprint = new RefundAllocationFingerprint();
        $one = [['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200]];
        $changed = [['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 201]];
        self::assertNotSame($fingerprint->hash('refund-1', $one), $fingerprint->hash('refund-1', $changed));
    }

    public function test_whitespace_only_suborder_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RefundAllocationFingerprint())->hash('refund-1', [
            ['suborder_id' => '   ', 'seller_id' => 1, 'amount_halalas' => 200],
        ]);
    }

    public function test_different_refund_has_different_fingerprint(): void
    {
        $fingerprint = new RefundAllocationFingerprint();
        $rows = [['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200]];
        self::assertNotSame($fingerprint->hash('refund-1', $rows), $fingerprint->hash('refund-2', $rows));
    }
}
