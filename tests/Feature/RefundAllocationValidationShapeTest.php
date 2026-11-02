<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Finance\RefundAllocationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RefundAllocationValidationShapeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidAllocations')]
    public function test_malformed_allocation_is_rejected_before_refund_lookup(array $allocations): void
    {
        try {
            app(RefundAllocationValidator::class)->validate(
                '01K00000000000000000000042',
                $allocations
            );
            self::fail('Malformed allocation must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('allocations', $exception->errors());
        }
    }

    public static function invalidAllocations(): array
    {
        return [
            'empty' => [[]],
            'missing seller' => [[['suborder_id' => 'sub-1', 'amount_halalas' => 200]]],
            'seller string' => [[['suborder_id' => 'sub-1', 'seller_id' => '1', 'amount_halalas' => 200]]],
            'zero amount' => [[['suborder_id' => 'sub-1', 'seller_id' => 1, 'amount_halalas' => 0]]],
            'negative amount' => [[['suborder_id' => 'sub-1', 'seller_id' => 1, 'amount_halalas' => -1]]],
            'noninteger amount' => [[['suborder_id' => 'sub-1', 'seller_id' => 1, 'amount_halalas' => '200']]],
            'blank suborder' => [[['suborder_id' => '', 'seller_id' => 1, 'amount_halalas' => 200]]],
        ];
    }
}
