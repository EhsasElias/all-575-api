<?php

namespace Tests\Feature\Addressing;

use App\Domain\Addressing\Models\Address;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddressBookTest extends TestCase
{
    use RefreshDatabase;

    private ServiceZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zone = ServiceZone::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'label' => 'Home',
            'recipient_name' => 'Sara Alharbi',
            'recipient_phone' => '0512345678',
            'region' => 'Riyadh Region',
            'city' => 'Riyadh',
            'district' => 'Al Olaya',
            'street' => 'King Fahd Road',
            'building_number' => '8228',
            'additional_number' => '2121',
            'postal_code' => '12611',
            'short_address' => 'rrrd2929',
            'lat' => 24.7136,
            'lng' => 46.6753,
        ];
    }

    public function test_guests_cannot_use_the_address_book(): void
    {
        $this->getJson('/api/v1/addresses')->assertUnauthorized();
    }

    public function test_it_saves_a_national_address_normalises_it_and_finds_the_service_zone(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/addresses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.recipient_phone', '+966512345678')
            ->assertJsonPath('data.short_address', 'RRRD2929')
            ->assertJsonPath('data.serviceable', true)
            ->assertJsonPath('data.service_zone_id', $this->zone->id)
            ->assertJsonPath('data.is_default', true);
    }

    public function test_an_address_outside_every_zone_is_saved_but_flagged_not_serviceable(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/addresses', $this->payload(['lat' => 21.5433, 'lng' => 39.1728]))
            ->assertCreated()
            ->assertJsonPath('data.serviceable', false)
            ->assertJsonPath('data.service_zone_id', null);
    }

    #[DataProvider('invalidNationalAddressFields')]
    public function test_it_rejects_malformed_national_address_fields(string $field, string $value): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/addresses', $this->payload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidNationalAddressFields(): array
    {
        return [
            'building number too short' => ['building_number', '822'],
            'building number with letters' => ['building_number', '82A8'],
            'additional number too long' => ['additional_number', '21211'],
            'postal code too short' => ['postal_code', '1261'],
            'short address without digits' => ['short_address', 'RRRDABCD'],
            'short address too short' => ['short_address', 'RRD2929'],
            'not a saudi mobile' => ['recipient_phone', '0112345678'],
        ];
    }

    public function test_the_map_pin_is_required_because_the_pin_alone_is_not_an_address_and_neither_is_text_alone(): void
    {
        $this->actingAsCustomer();
        $payload = $this->payload();
        unset($payload['lat'], $payload['lng']);

        $this->postJson('/api/v1/addresses', $payload)->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_only_one_address_is_the_default_and_the_default_can_be_moved(): void
    {
        $user = $this->actingAsCustomer();
        $first = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Home']))->json('data.id');
        $second = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Work']))->assertJsonPath('data.is_default', false)->json('data.id');

        $this->putJson("/api/v1/addresses/{$second}/default")->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertFalse(Address::query()->findOrFail($first)->is_default);
        $this->assertSame(1, $user->addresses()->where('is_default', true)->count());
    }

    public function test_deleting_the_default_promotes_another_address(): void
    {
        $user = $this->actingAsCustomer();
        $first = $this->postJson('/api/v1/addresses', $this->payload())->json('data.id');
        $second = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Work']))->json('data.id');

        $this->deleteJson("/api/v1/addresses/{$first}")->assertNoContent();

        $this->assertTrue(Address::query()->findOrFail($second)->is_default);
        $this->assertSame(1, $user->addresses()->count());
    }

    public function test_editing_the_pin_re_resolves_the_service_zone(): void
    {
        $this->actingAsCustomer();
        $id = $this->postJson('/api/v1/addresses', $this->payload())->assertJsonPath('data.serviceable', true)->json('data.id');

        $this->putJson("/api/v1/addresses/{$id}", $this->payload(['lat' => 21.5433, 'lng' => 39.1728]))
            ->assertOk()
            ->assertJsonPath('data.serviceable', false);
    }

    public function test_a_user_cannot_read_change_or_delete_someone_elses_address(): void
    {
        $owner = User::factory()->create();
        $theirs = Address::factory()->create(['user_id' => $owner->id, 'service_zone_id' => $this->zone->id]);
        $this->actingAsCustomer();

        $this->getJson("/api/v1/addresses/{$theirs->id}")->assertNotFound();
        $this->putJson("/api/v1/addresses/{$theirs->id}", $this->payload())->assertNotFound();
        $this->putJson("/api/v1/addresses/{$theirs->id}/default")->assertNotFound();
        $this->deleteJson("/api/v1/addresses/{$theirs->id}")->assertNotFound();
        $this->assertDatabaseHas('addresses', ['id' => $theirs->id]);
    }

    public function test_the_address_list_only_contains_the_users_own_addresses(): void
    {
        Address::factory()->create(['service_zone_id' => $this->zone->id]);
        $user = $this->actingAsCustomer();
        Address::factory()->create(['user_id' => $user->id, 'service_zone_id' => $this->zone->id]);

        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_address_book_is_capped(): void
    {
        $user = $this->actingAsCustomer();
        Address::factory()->count(10)->create(['user_id' => $user->id, 'service_zone_id' => $this->zone->id]);

        $this->postJson('/api/v1/addresses', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'address_limit_reached');
    }
}
