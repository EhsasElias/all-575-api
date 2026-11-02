<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Finance\RecordRefundAllocations;
use App\Domain\Finance\RefundAllocationFingerprint;
use App\Domain\Finance\TwoPersonApproval;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class RefundAllocationExecutionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function allocation(): array
    {
        return [['suborder_id' => '01K00000000000000000000001', 'seller_id' => 1, 'amount_halalas' => 200]];
    }

    private function grant(int $userId, string $role): void
    {
        DB::table('finance_approver_grants')->insert([
            'user_id' => $userId, 'role' => $role, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_execution_rejects_unapproved_allocation_even_with_active_finance_grant(): void
    {
        $reviewer = User::factory()->create();
        $this->grant($reviewer->id, 'second');

        $this->expectException(InvalidArgumentException::class);
        app(RecordRefundAllocations::class)->record('01K00000000000000000000002', $this->allocation(), $reviewer->id);
    }

    public function test_execution_rejects_approved_request_with_changed_amount(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $refundId = '01K00000000000000000000003';
        $allocation = $this->allocation();
        $hash = app(RefundAllocationFingerprint::class)->hash($refundId, $allocation);

        $approval = app(TwoPersonApproval::class)->request('refund_allocation', $refundId, $first->id, $hash);
        app(TwoPersonApproval::class)->approve($approval, $second->id);

        $allocation[0]['amount_halalas'] = 201;

        try {
            app(RecordRefundAllocations::class)->record($refundId, $allocation, $second->id);
            self::fail('Changed amounts must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Matching two-person approval is required.', $exception->getMessage());
        }

        $this->assertDatabaseHas('finance_approval_requests', ['id' => $approval, 'status' => 'approved']);
        $this->assertDatabaseMissing('finance_audit_events', [
            'approval_request_id' => $approval,
            'action' => 'refund_allocation_recorded',
        ]);
        self::assertSame(0, DB::table('seller_refund_allocations')->count());
    }
}
