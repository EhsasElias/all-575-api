<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_response_carries_basic_security_headers(): void
    {
        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_responses_for_a_signed_in_customer_are_never_cacheable(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/me')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_otp_hourly_limit_cannot_be_dodged_by_writing_the_same_number_differently(): void
    {
        $formats = ['0512345678', '+966512345678', '966512345678', '512345678', '0512 345 678'];

        foreach ($formats as $format) {
            $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => $format])->assertAccepted();
            $this->travel(2)->minutes();
        }

        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => '٠٥١٢٣٤٥٦٧٨'])->assertStatus(429);
    }

    public function test_only_listed_origins_get_cross_origin_access(): void
    {
        $allowed = $this->withHeaders(['Origin' => 'capacitor://localhost'])->getJson('/api/v1/categories');
        $other = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/categories');

        $this->assertSame('capacitor://localhost', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($other->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_plain_http_origin_of_the_android_test_build_is_accepted_by_a_local_server_only(): void
    {
        $originsFor = function (string $environment): array {
            $server = $_SERVER['APP_ENV'] ?? null;
            $env = $_ENV['APP_ENV'] ?? null;
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $environment;

            try {
                return (require config_path('cors.php'))['allowed_origins'];
            } finally {
                $_SERVER['APP_ENV'] = $server;
                $_ENV['APP_ENV'] = $env;
            }
        };

        $this->assertContains('http://localhost', $originsFor('local'));
        $this->assertNotContains('http://localhost', $originsFor('production'));
        $this->assertNotContains('http://localhost', $originsFor('testing'));
    }

    public function test_issued_tokens_carry_a_prefix_that_secret_scanners_can_recognise(): void
    {
        $token = User::factory()->create()->createToken('Pixel 8', ['customer'])->plainTextToken;

        $this->assertStringContainsString('|a575_', $token);
    }

    public function test_unknown_routes_and_methods_answer_in_json_without_leaking_internals(): void
    {
        $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonMissing(['exception']);
        $this->deleteJson('/api/v1/categories')->assertStatus(405);
    }
}
