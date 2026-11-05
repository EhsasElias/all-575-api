<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            // Isolate Nominatim tests from local .env / cached Geoapify settings.
            // Geoapify-specific tests explicitly opt in with a fake credential.
            'services.geocoding.provider' => 'nominatim',
            'services.geocoding.geoapify_key' => null,
            'services.geocoding.url' => 'https://geocoding.example.test/reverse',
            'services.geocoding.fallback_url' => null,
        ]);
    }

    public function test_lookup_requires_customer_and_valid_coordinates(): void
    {
        $this->getJson('/api/v1/addresses/resolve?lat=24&lng=46')->assertUnauthorized();
        $this->actingAsCustomer(User::factory()->create());
        $this->getJson('/api/v1/addresses/resolve?lat=91&lng=46')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_localized_suggestions_are_cached_and_do_not_send_account_details(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        Http::fake(['geocoding.example.test/*' => Http::response(['address' => [
            'country_code' => 'sa', 'state' => 'منطقة الرياض', 'city' => 'الرياض',
            'suburb' => 'العليا', 'road' => 'شارع تجريبي', 'house_number' => '1234', 'postcode' => '12211',
        ]])]);
        $url = '/api/v1/addresses/resolve?lat=24.7136&lng=46.6753';
        $this->getJson($url, ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.city', 'الرياض')->assertJsonPath('data.building_number', '1234');
        $this->getJson($url, ['Accept-Language' => 'ar'])->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['accept-language'] === 'ar'
            && count($request->data()) === 5 && str_contains($request->header('User-Agent')[0], 'ALL575'));
        $this->getJson('/api/v1/addresses/resolve?lat=25&lng=46')->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_geoapify_free_provider_autofills_saudi_address_without_leaking_key(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        config([
            'services.geocoding.provider' => 'geoapify',
            'services.geocoding.geoapify_key' => 'fake-private-key',
        ]);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => [[
                'properties' => [
                    'country_code' => 'sa', 'state' => 'Riyadh Region', 'city' => 'Riyadh',
                    'suburb' => 'Olaya', 'street' => 'King Fahd Road',
                    'housenumber' => '1234', 'postcode' => '12211',
                ],
            ]]]),
        ]);
        $this->getJson('/api/v1/addresses/resolve?lat=24.7136&lng=46.6753')
            ->assertOk()->assertJsonPath('data.city', 'Riyadh')
            ->assertJsonPath('data.district', 'Olaya')
            ->assertJsonPath('data.street', 'King Fahd Road')
            ->assertJsonPath('data.postal_code', '12211')
            ->assertDontSee('fake-private-key');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.geoapify.com')
            && $request['apiKey'] === 'fake-private-key');
    }

    public function test_geoapify_provider_failure_uses_nominatim_fallback(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        config([
            'services.geocoding.provider' => 'geoapify',
            'services.geocoding.geoapify_key' => 'test-key',
        ]);
        Http::fake([
            'api.geoapify.com/*' => Http::response([], 503),
            'geocoding.example.test/*' => Http::response(['address' => [
                'country_code' => 'sa', 'city' => 'Riyadh',
            ]]),
        ]);
        $this->getJson('/api/v1/addresses/resolve?lat=24.7136&lng=46.6753')
            ->assertOk()->assertJsonPath('data.city', 'Riyadh');
        Http::assertSentCount(2);
    }

    public function test_secondary_provider_is_used_when_primary_cannot_connect(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        config(['services.geocoding.fallback_url' => 'https://backup.example.test/reverse']);
        Http::fake([
            'geocoding.example.test/*' => Http::failedConnection(),
            'backup.example.test/*' => Http::response(['address' => [
                'country_code' => 'sa', 'city' => 'Riyadh', 'road' => 'Test road',
            ]]),
        ]);

        $url = '/api/v1/addresses/resolve?lat=24.7136&lng=46.6753';
        $this->getJson($url)->assertOk()->assertJsonPath('data.city', 'Riyadh');
        $this->getJson($url)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_both_providers_failing_returns_safe_error_without_provider_data(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        config(['services.geocoding.fallback_url' => 'https://backup.example.test/reverse']);
        Http::fake([
            'geocoding.example.test/*' => Http::response(['secret' => 'primary'], 503),
            'backup.example.test/*' => Http::response(['secret' => 'backup'], 502),
        ]);

        $this->getJson('/api/v1/addresses/resolve?lat=24&lng=46')
            ->assertStatus(503)->assertDontSee('primary')->assertDontSee('backup');
        Http::assertSentCount(2);
    }

    public function test_missing_or_invalid_numbers_are_not_invented(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        Http::fake(['*' => Http::response(['address' => [
            'country_code' => 'sa', 'town' => 'Test town', 'house_number' => '12A', 'postcode' => '12345-6789',
        ]])]);
        $this->getJson('/api/v1/addresses/resolve?lat=24&lng=46')->assertOk()
            ->assertJsonPath('data.city', 'Test town')->assertJsonPath('data.street', null)
            ->assertJsonPath('data.building_number', null)->assertJsonPath('data.postal_code', null);
    }

    public function test_foreign_address_is_not_copied_into_saudi_form(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        Http::fake(['*' => Http::response(['address' => ['country_code' => 'gb', 'city' => 'London']])]);
        $this->getJson('/api/v1/addresses/resolve?lat=51&lng=0')->assertOk()
            ->assertJsonPath('data.country_code', 'gb')->assertJsonPath('data.city', null);
    }

    public function test_provider_failure_or_timeout_allows_manual_fallback_without_leaking_provider_response(): void
    {
        $this->actingAsCustomer(User::factory()->create());
        Http::fake(['*' => Http::response(['provider_debug' => 'private'], 500)]);
        $this->getJson('/api/v1/addresses/resolve?lat=24&lng=46')->assertStatus(503)->assertDontSee('private');
        Cache::flush();
        Http::fake(['*' => Http::failedConnection()]);
        $this->getJson('/api/v1/addresses/resolve?lat=24&lng=46')->assertStatus(503);
    }
}
