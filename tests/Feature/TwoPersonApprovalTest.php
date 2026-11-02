<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Finance\TwoPersonApproval;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TwoPersonApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_person_cannot_approve_twice(): void
    {
        $user = User::factory()->create();
        $this->grant($user->id, 'first');
        $this->grant($user->id, 'second');
        $service = app(TwoPersonApproval::class);
        $id = $service->request('refund_allocation', 'test-reference-one', $user->id);

        $this->expectException(ValidationException::class);
        $service->approve($id, $user->id);
    }

    public function test_two_people_can_approve_only_once(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $service = app(TwoPersonApproval::class);
        $id = $service->request('refund_allocation', 'test-reference-two', $first->id);

        $service->approve($id, $second->id);
        $this->assertDatabaseHas('finance_approval_requests', [
            'id' => $id,
            'status' => 'approved',
            'first_approver_id' => $first->id,
            'second_approver_id' => $second->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->approve($id, $second->id);
    }
    public function test_ungranted_user_cannot_create_approval(): void
    {
        $user = User::factory()->create();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TwoPersonApproval::class)->request('refund_allocation', 'ungranted', $user->id);
    }

    public function test_second_reviewer_can_reject_with_reason(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $service = app(TwoPersonApproval::class);
        $id = $service->request('refund_allocation', 'rejected-ref', $first->id);

        $service->reject($id, $second->id, 'Missing supporting evidence for the refund.');
        $this->assertDatabaseHas('finance_approval_requests', [
            'id' => $id,
            'status' => 'rejected',
            'rejected_by' => $second->id,
        ]);

        $this->expectException(ValidationException::class);
        $service->approve($id, $second->id);
    }

    private function grant(int $userId, string $role): void
    {
        DB::table('finance_approver_grants')->insert([
            'user_id' => $userId,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
