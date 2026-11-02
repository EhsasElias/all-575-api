<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Finance\TwoPersonApproval;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TwoPersonApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_person_cannot_approve_twice(): void
    {
        $user = User::factory()->create();
        $service = app(TwoPersonApproval::class);
        $id = $service->request('refund_allocation', 'test-reference-one', $user->id);

        $this->expectException(ValidationException::class);
        $service->approve($id, $user->id);
    }

    public function test_two_people_can_approve_only_once(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
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
}
