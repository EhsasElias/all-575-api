<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use App\Domain\Finance\TwoPersonApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FinanceReviewEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_finance_approvals(): void
    {
        $this->getJson('/api/v1/finance/approvals')->assertUnauthorized();
        $this->getJson('/api/v1/finance/approvals/1/audit')->assertUnauthorized();
    }

    public function test_authorized_reviewer_can_read_approval_and_its_audit_history(): void
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

        $this->actingAs($first);
        $approvalId = app(TwoPersonApproval::class)->request(
            'refund_allocation',
            '01K00000000000000000000040',
            $first->id,
            hash('sha256', 'review-fixture')
        );

        $this->actingAs($second);
        app(TwoPersonApproval::class)->approve($approvalId, $second->id);

        $this->getJson('/api/v1/finance/approvals?status=approved')
            ->assertOk()
            ->assertJsonFragment(['id' => $approvalId, 'status' => 'approved']);

        $this->getJson('/api/v1/finance/approvals/'.$approvalId.'/audit')
            ->assertOk()
            ->assertJsonFragment(['action' => 'approval_requested', 'actor_user_id' => $first->id])
            ->assertJsonFragment(['action' => 'approval_granted', 'actor_user_id' => $second->id]);
    }

    public function test_revoking_finance_grant_immediately_blocks_approval_and_audit_reads(): void
    {
        $reviewer = User::factory()->create();

        DB::table('finance_approver_grants')->insert([
            'user_id' => $reviewer->id,
            'role' => 'second',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($reviewer);
        $this->getJson('/api/v1/finance/approvals')->assertOk();

        DB::table('finance_approver_grants')
            ->where('user_id', $reviewer->id)
            ->where('role', 'second')
            ->update(['is_active' => false]);

        $this->getJson('/api/v1/finance/approvals')->assertForbidden();
        $this->getJson('/api/v1/finance/approvals/1/audit')->assertForbidden();
    }

    public function test_authenticated_user_without_finance_grant_cannot_read_approvals(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/finance/approvals')->assertForbidden();
        $this->getJson('/api/v1/finance/approvals/1/audit')->assertForbidden();
    }
}
