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
        $this->actingAs($reviewer);

        $this->expectException(InvalidArgumentException::class);
        app(RecordRefundAllocations::class)->record('01K00000000000000000000002', $this->allocation(), $reviewer->id);
    }

    public function test_executor_cannot_impersonate_another_finance_approver(): void
    {
        $reviewer = User::factory()->create();
        $outsider = User::factory()->create();
        $this->grant($reviewer->id, 'second');
        $this->actingAs($outsider);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RecordRefundAllocations::class)->record(
            '01K00000000000000000000009',
            $this->allocation(),
            $reviewer->id
        );
    }

    public function test_executed_approval_cannot_be_replayed(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $this->actingAs($second);

        $refundId = '01K00000000000000000000004';
        $allocations = $this->allocation();
        $hash = app(RefundAllocationFingerprint::class)->hash($refundId, $allocations);
        $this->actingAs($first);
        $id = app(TwoPersonApproval::class)->request('refund_allocation', $refundId, $first->id, $hash);
        $this->actingAs($second);
        app(TwoPersonApproval::class)->approve($id, $second->id);

        DB::table('finance_approval_requests')->where('id', $id)->update(['status' => 'executed']);

        $this->expectException(InvalidArgumentException::class);
        app(RecordRefundAllocations::class)->record($refundId, $allocations, $second->id);
    }

    public function test_revoked_second_approver_cannot_execute(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $this->actingAs($second);

        $refundId = '01K00000000000000000000005';
        $allocations = $this->allocation();
        $hash = app(RefundAllocationFingerprint::class)->hash($refundId, $allocations);
        $this->actingAs($first);
        $id = app(TwoPersonApproval::class)->request('refund_allocation', $refundId, $first->id, $hash);
        $this->actingAs($second);
        app(TwoPersonApproval::class)->approve($id, $second->id);

        DB::table('finance_approver_grants')->where('user_id', $second->id)
            ->where('role', 'second')->update(['is_active' => false]);

        try {
            app(RecordRefundAllocations::class)->record($refundId, $allocations, $second->id);
            self::fail('Revoked financial permission must prevent execution.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('finance_approval_requests', ['id' => $id, 'status' => 'approved']);
        $this->assertDatabaseMissing('finance_audit_events', [
            'approval_request_id' => $id, 'action' => 'refund_allocation_recorded',
        ]);
    }

    public function test_execution_rejects_approved_request_with_changed_amount(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $this->actingAs($second);
        $refundId = '01K00000000000000000000003';
        $allocation = $this->allocation();
        $hash = app(RefundAllocationFingerprint::class)->hash($refundId, $allocation);

        $this->actingAs($first);
        $approval = app(TwoPersonApproval::class)->request('refund_allocation', $refundId, $first->id, $hash);
        $this->actingAs($second);
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
