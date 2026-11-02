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
        $this->actingAs($user);
        $id = $service->request('refund_allocation', 'test-reference-one', $user->id, hash('sha256', 'fixture'));

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
        $this->actingAs($first);
        $id = $service->request('refund_allocation', 'test-reference-two', $first->id, hash('sha256', 'fixture'));

        $this->actingAs($second);
        $service->approve($id, $second->id);
        $this->assertDatabaseHas('finance_audit_events', ['approval_request_id' => $id, 'actor_user_id' => $first->id, 'action' => 'approval_requested']);
        $this->assertDatabaseHas('finance_audit_events', ['approval_request_id' => $id, 'actor_user_id' => $second->id, 'action' => 'approval_granted']);
        $this->assertDatabaseHas('finance_approval_requests', [
            'id' => $id,
            'status' => 'approved',
            'first_approver_id' => $first->id,
            'second_approver_id' => $second->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->actingAs($second);
        $service->approve($id, $second->id);
    }
    public function test_ungranted_user_cannot_create_approval(): void
    {
        $user = User::factory()->create();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->actingAs($user);
        app(TwoPersonApproval::class)->request('refund_allocation', 'ungranted', $user->id, hash('sha256', 'fixture'));
    }

    public function test_second_reviewer_can_reject_with_reason(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $service = app(TwoPersonApproval::class);
        $this->actingAs($first);
        $id = $service->request('refund_allocation', 'rejected-ref', $first->id, hash('sha256', 'fixture'));

        $this->actingAs($second);
        $service->reject($id, $second->id, 'Missing supporting evidence for the refund.');
        $this->assertDatabaseHas('finance_audit_events', ['approval_request_id' => $id, 'actor_user_id' => $second->id, 'action' => 'approval_rejected']);
        $this->assertDatabaseHas('finance_approval_requests', [
            'id' => $id,
            'status' => 'rejected',
            'rejected_by' => $second->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->actingAs($second);
        $service->approve($id, $second->id);
    }

    public function test_revoked_second_approver_cannot_approve(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $service = app(TwoPersonApproval::class);
        $this->actingAs($first);
        $id = $service->request('refund_allocation', 'revoked-ref', $first->id, hash('sha256', 'fixture'));

        DB::table('finance_approver_grants')
            ->where('user_id', $second->id)->where('role', 'second')
            ->update(['is_active' => false]);

        $this->actingAs($second);
        try {
            $service->approve($id, $second->id);
            self::fail('Revoked approver must be denied.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('finance_approval_requests', [
            'id' => $id,
            'status' => 'pending_second_approval',
        ]);
        $this->assertDatabaseMissing('finance_audit_events', [
            'approval_request_id' => $id,
            'action' => 'approval_granted',
        ]);
    }

    public function test_rejected_request_cannot_be_rejected_twice(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');
        $service = app(TwoPersonApproval::class);
        $this->actingAs($first);
        $id = $service->request('refund_allocation', 'reject-once', $first->id, hash('sha256', 'fixture'));
        $this->actingAs($second);
        $service->reject($id, $second->id, 'Insufficient refund documentation.');

        $this->expectException(ValidationException::class);
        $this->actingAs($second);
        $service->reject($id, $second->id, 'Another rejection should not succeed.');
    }

    public function test_duplicate_approval_request_cannot_create_extra_audit_event(): void
    {
        $first = User::factory()->create();
        $this->grant($first->id, 'first');
        $service = app(TwoPersonApproval::class);
        $this->actingAs($first);
        $id = $service->request('refund_allocation', 'duplicate-ref', $first->id, hash('sha256', 'fixture'));

        try {
            $service->request('refund_allocation', 'duplicate-ref', $first->id, hash('sha256', 'fixture'));
            self::fail('Duplicate request must be rejected.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        }

        self::assertSame(1, DB::table('finance_audit_events')
            ->where('approval_request_id', $id)
            ->where('action', 'approval_requested')->count());
    }

    public function test_refund_approval_cannot_start_without_payload_fingerprint(): void
    {
        $first = User::factory()->create();
        $this->grant($first->id, 'first');

        $this->expectException(ValidationException::class);
        $this->actingAs($first);
        app(TwoPersonApproval::class)->request('refund_allocation', 'missing-fingerprint', $first->id);
    }

    public function test_finance_actor_id_cannot_be_spoofed(): void
    {
        $first = User::factory()->create();
        $outsider = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->actingAs($outsider);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TwoPersonApproval::class)->request(
            'refund_allocation',
            'spoofed-request',
            $first->id,
            hash('sha256', 'fixture')
        );
    }

    public function test_unauthenticated_user_cannot_start_approval_even_with_grant(): void
    {
        $first = User::factory()->create();
        $this->grant($first->id, 'first');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TwoPersonApproval::class)->request(
            'refund_allocation', 'unauthenticated-ref', $first->id, hash('sha256', 'fixture')
        );
    }

    public function test_revoking_first_approver_blocks_second_approval(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->grant($first->id, 'first');
        $this->grant($second->id, 'second');

        $this->actingAs($first);
        $id = app(TwoPersonApproval::class)->request(
            'refund_allocation', 'revoked-first-before-approval', $first->id, hash('sha256', 'fixture')
        );

        DB::table('finance_approver_grants')
            ->where('user_id', $first->id)
            ->where('role', 'first')
            ->update(['is_active' => false]);

        $this->actingAs($second);
        try {
            app(TwoPersonApproval::class)->approve($id, $second->id);
            self::fail('Revoked first approver must prevent second approval.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('finance_approval_requests', [
            'id' => $id, 'status' => 'pending_second_approval',
        ]);
        $this->assertDatabaseMissing('finance_audit_events', [
            'approval_request_id' => $id, 'action' => 'approval_granted',
        ]);
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
