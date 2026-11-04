<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SellerExtendedApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_seller_applicant_can_save_extended_optional_company_and_address_fields(): void
    {
        $this->actingAsCustomer(User::factory()->create(['email_verified_at' => now()]));

        $this->putJson('/api/v1/seller/application', [
            'legal_name' => 'Sample Trading LLC',
            'commercial_registration' => '1010123456',
            'contact_name' => 'Seller Representative',
            'contact_email' => 'sales@example.com',
            'contact_phone' => '0500000000',
            'city' => 'Riyadh',
            'entity_type' => 'LLC',
            'commercial_registration_status' => 'active',
            'commercial_registration_issue_date' => '2024-01-01',
            'commercial_registration_expiry_date' => '2030-01-01',
            'chamber_of_commerce_number' => '12345',
            'building_number' => '1234',
            'postal_code' => '12345',
            'additional_number' => '5678',
            'street' => 'Test Street',
            'district' => 'Test District',
            'iban' => 'SA0380000000608010167519',
        ])->assertOk()
            ->assertJsonPath('data.entity_type', 'LLC')
            ->assertJsonPath('data.postal_code', '12345')
            ->assertJsonPath('data.iban', 'SA0380000000608010167519');

        $rawIban = \Illuminate\\Support\\Facades\\DB::table('seller_applications')->value('iban');
        $this->assertNotSame('SA0380000000608010167519', $rawIban);
        $this->assertSame('SA0380000000608010167519',
            \Illuminate\\Support\\Facades\\Crypt::decryptString($rawIban));

        $this->getJson('/api/v1/seller/application')->assertOk()
            ->assertJsonPath('data.building_number', '1234')
            ->assertJsonPath('data.commercial_registration_expiry_date', '2030-01-01');
    }

    public function test_expiry_date_before_issue_date_is_rejected(): void
    {
        $this->actingAsCustomer(User::factory()->create(['email_verified_at' => now()]));

        $this->putJson('/api/v1/seller/application', [
            'legal_name' => 'Sample Trading LLC',
            'commercial_registration' => '1010123456',
            'contact_name' => 'Seller Representative',
            'contact_email' => 'sales@example.com',
            'contact_phone' => '0500000000',
            'city' => 'Riyadh',
            'commercial_registration_issue_date' => '2030-01-01',
            'commercial_registration_expiry_date' => '2024-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('commercial_registration_expiry_date');
    }
}
