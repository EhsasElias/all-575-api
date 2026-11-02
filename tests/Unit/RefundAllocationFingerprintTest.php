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

    public function test_duplicate_suborder_is_rejected_before_approval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RefundAllocationFingerprint())->hash('refund-1', [
            ['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200],
            ['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 100],
        ]);
    }

    public function test_whitespace_only_refund_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RefundAllocationFingerprint())->hash('   ', [
            ['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200],
        ]);
    }

    public function test_changing_seller_invalidates_fingerprint(): void
    {
        $fingerprint = new RefundAllocationFingerprint();
        $original = [['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200]];
        $changed = [['suborder_id' => 'a', 'seller_id' => 2, 'amount_halalas' => 200]];

        self::assertNotSame($fingerprint->hash('refund-1', $original), $fingerprint->hash('refund-1', $changed));
    }

    public function test_identical_payload_produces_the_same_fingerprint(): void
    {
        $fingerprint = new RefundAllocationFingerprint();
        $allocation = [['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200]];

        self::assertSame($fingerprint->hash('refund-1', $allocation), $fingerprint->hash('refund-1', $allocation));
    }

    public function test_non_array_allocation_row_is_rejected_cleanly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RefundAllocationFingerprint())->hash('refund-1', ['invalid-row']);
    }

    public function test_different_refund_has_different_fingerprint(): void
    {
        $fingerprint = new RefundAllocationFingerprint();
        $rows = [['suborder_id' => 'a', 'seller_id' => 1, 'amount_halalas' => 200]];
        self::assertNotSame($fingerprint->hash('refund-1', $rows), $fingerprint->hash('refund-2', $rows));
    }
}
