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
        config(['services.geocoding.url' => 'https://geocoding.example.test/reverse']);
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
