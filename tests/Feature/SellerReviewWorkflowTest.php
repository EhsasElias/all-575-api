<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SellerReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function application(): int
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DB::table('seller_applications')->insert([
            'user_id' => $user->id,
            'status' => 'submitted',
            'legal_name' => 'Test Seller Ltd',
            'commercial_registration' => '1010000000',
            'contact_name' => 'Sample Manager',
            'contact_email' => 'sample@example.com',
            'contact_phone' => '0500000000',
            'city' => 'Riyadh',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('seller_applications')->where('user_id', $user->id)->value('id');
    }

    private function reviewer(bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => true]);
        DB::table('seller_review_grants')->insert([
            'user_id' => $user->id,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($user, ['customer']);

        return $user;
    }

    private function documents(int $application): void
    {
        foreach (['commercial_registration', 'national_address', 'iban_certificate'] as $type) {
            DB::table('seller_application_documents')->insert([
                'application_id' => $application,
                'document_type' => $type,
                'file_path' => 'seller-applications/'.$application.'/'.$type.'.pdf',
                'original_name' => $type.'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 123,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_review_endpoints_denied_without_explicit_grant(): void
    {
        $application = $this->application();
        Sanctum::actingAs(User::factory()->create(), ['customer']);

        $this->getJson('/api/v1/seller-review/applications')->assertForbidden();
        $this->getJson('/api/v1/seller-review/applications/'.$application)->assertForbidden();
        $this->postJson('/api/v1/seller-review/applications/'.$application.'/decision',
            ['decision' => 'approve'])->assertForbidden();
        $this->getJson('/api/v1/seller-review/products')->assertForbidden();

        $this->assertDatabaseCount('sellers', 0);
    }

    public function test_inactive_reviewer_grant_does_not_authorize_decisions(): void
    {
        $application = $this->application();
        $this->reviewer(false);

        $this->postJson('/api/v1/seller-review/applications/'.$application.'/decision',
            ['decision' => 'approve'])->assertForbidden();
    }

    public function test_approval_requires_private_documents_and_creates_owner_once(): void
    {
        $application = $this->application();
        $this->reviewer();

        $endpoint = '/api/v1/seller-review/applications/'.$application.'/decision';
        $this->postJson($endpoint, ['decision' => 'approve'])->assertStatus(422);
        $this->assertDatabaseCount('sellers', 0);

        $this->documents($application);

        $response = $this->postJson($endpoint, ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $sellerId = (int) $response->json('data.seller_id');
        $applicantId = (int) DB::table('seller_applications')->where('id', $application)->value('user_id');

        $this->assertDatabaseHas('seller_staff_access', [
            'seller_id' => $sellerId, 'user_id' => $applicantId, 'role' => 'owner', 'is_active' => true,
        ]);
        $this->assertDatabaseHas('seller_review_audits', [
            'subject_type' => 'application', 'subject_id' => $application,
            'previous_status' => 'submitted', 'new_status' => 'approved',
        ]);
        $this->assertDatabaseHas('sellers', ['id' => $sellerId, 'status' => 'approved']);

        $this->postJson($endpoint, ['decision' => 'approve'])->assertStatus(409);
        $this->assertDatabaseCount('sellers', 1);
    }

    public function test_correction_records_review_note_without_creating_store(): void
    {
        $application = $this->application();
        $this->reviewer();

        $this->postJson('/api/v1/seller-review/applications/'.$application.'/decision', [
            'decision' => 'correction',
            'reason' => 'Please correct the legal registration information.',
        ])->assertOk()->assertJsonPath('data.status', 'correction');

        $this->assertDatabaseHas('seller_applications', [
            'id' => $application,
            'status' => 'correction',
            'review_note' => 'Please correct the legal registration information.',
        ]);
        $this->assertDatabaseCount('sellers', 0);
    }
}
