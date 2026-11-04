<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SellerApplicationDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function saveApplication(): void
    {
        $this->putJson('/api/v1/seller/application', [
            'legal_name' => 'Sample Trading Co',
            'commercial_registration' => '1010123456',
            'national_unified_number' => '7001234567',
            'vat_number' => '300000000000003',
            'contact_name' => 'Test Manager',
            'contact_email' => 'manager@example.com',
            'contact_phone' => '0500000000',
            'city' => 'Riyadh',
        ])->assertOk();
    }

    public function test_verified_applicant_can_upload_private_document_and_list_metadata(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAsCustomer($user);
        $this->saveApplication();

        $this->post('/api/v1/seller/application/documents', [
            'document_type' => 'commercial_registration',
            'file' => UploadedFile::fake()->create('registration.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.document_type', 'commercial_registration');

        $this->getJson('/api/v1/seller/application/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'registration.pdf');

        $document = \Illuminate\Support\Facades\DB::table('seller_application_documents')->first();
        $this->assertNotNull($document);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringNotContainsString('public', $document->file_path);
    }

    public function test_unverified_account_cannot_upload_document(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email_verified_at' => null, 'phone_verified_at' => null]);
        $this->actingAsCustomer($user);

        $this->post('/api/v1/seller/application/documents', [
            'document_type' => 'commercial_registration',
            'file' => UploadedFile::fake()->create('registration.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_submitted_application_cannot_replace_document(): void
    {
        Storage::fake('local');
        $this->actingAsCustomer(User::factory()->create(['email_verified_at' => now()]));
        $this->saveApplication();
        $this->postJson('/api/v1/seller/application/submit')->assertOk();

        $this->post('/api/v1/seller/application/documents', [
            'document_type' => 'commercial_registration',
            'file' => UploadedFile::fake()->create('registration.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertDatabaseCount('seller_application_documents', 0);
    }
}
