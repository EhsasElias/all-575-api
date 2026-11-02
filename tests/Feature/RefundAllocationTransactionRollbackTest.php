<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Finance\RecordRefundAllocations;
use App\Domain\Finance\RefundAllocationFingerprint;
use App\Domain\Finance\RefundAllocationValidator;
use App\Domain\Finance\TwoPersonApproval;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RefundAllocationTransactionRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_allocation_validation_leaves_approval_and_audit_unchanged(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach ([[$first->id, 'first'], [$second->id, 'second']] as [$userId, $role]) {
            DB::table('finance_approver_grants')->insert([
                'user_id' => $userId,
                'role' => $role,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $refundId = '01K00000000000000000000010';
        $allocations = [[
            'suborder_id' => '01K00000000000000000000011',
            'seller_id' => 1,
            'amount_halalas' => 200,
        ]];
        $fingerprint = app(RefundAllocationFingerprint::class)->hash($refundId, $allocations);

        $this->actingAs($first);
        $approvalId = app(TwoPersonApproval::class)
            ->request('refund_allocation', $refundId, $first->id, $fingerprint);
        $this->actingAs($second);
        app(TwoPersonApproval::class)->approve($approvalId, $second->id);

        $validator = $this->mock(RefundAllocationValidator::class);
        $validator->shouldReceive('validate')
            ->once()->with($refundId, $allocations)
            ->andThrow(ValidationException::withMessages(['allocations' => 'Invalid seller allocation.']));

        try {
            app(RecordRefundAllocations::class)->record($refundId, $allocations, $second->id);
            self::fail('Invalid allocation must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('allocations', $exception->errors());
        }

        $this->assertDatabaseHas('finance_approval_requests', [
            'id' => $approvalId,
            'status' => 'approved',
        ]);
        $this->assertDatabaseMissing('finance_audit_events', [
            'approval_request_id' => $approvalId,
            'action' => 'refund_allocation_recorded',
        ]);
        self::assertSame(0, DB::table('seller_refund_allocations')->count());
    }
}
