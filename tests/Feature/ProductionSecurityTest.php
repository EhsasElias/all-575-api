<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_challenges_are_not_cacheable_even_without_a_token(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => '0512345678'])
            ->assertAccepted()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_errors_and_preflight_responses_keep_security_headers(): void
    {
        foreach ([['GET', '/api/v1/me', 401], ['GET', '/api/v1/missing', 404],
            ['DELETE', '/api/v1/categories', 405], ['POST', '/api/v1/auth/otp/request', 422]] as [$method, $uri, $status]) {
            $this->json($method, $uri)->assertStatus($status)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        $this->withHeaders(['Origin' => 'https://localhost', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/v1/categories')->assertNoContent()
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_rate_limits_keep_retry_after_and_no_store(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $response = $this->postJson('/api/v1/auth/otp/verify', []);
        }
        $response->assertStatus(429)->assertHeader('Retry-After')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_unhandled_errors_are_protected_without_exposing_the_exception(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/security-test-error', fn () => throw new \RuntimeException('internal-secret'));
        $this->getJson('/api/v1/security-test-error')->assertStatus(500)
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('internal-secret');
    }

    public function test_hsts_is_only_sent_over_https_in_production(): void
    {
        $this->getJson('https://localhost/api/v1/categories')->assertHeaderMissing('Strict-Transport-Security');
        $this->app['env'] = 'production';
        $this->getJson('https://localhost/api/v1/categories')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->getJson('http://localhost/api/v1/categories')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_read_only_check_rejects_local_debug_and_simulated_providers_without_printing_keys(): void
    {
        config(['app.key' => 'do-not-print-this-key']);
        $this->artisan('security:check')->expectsOutput('FAIL: APP_ENV must be production.')
            ->doesntExpectOutput('do-not-print-this-key')->assertFailed();
    }

    public function test_read_only_check_accepts_safe_config_but_does_not_claim_providers_are_ready(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'https://api.market575.com', 'cache.default' => 'database',
            'store.otp.driver' => 'unconfigured', 'store.payments.driver' => 'unconfigured',
            'cors.allowed_origins' => ['https://market575.com', 'https://localhost', 'capacitor://localhost']]);
        $this->artisan('security:check')->expectsOutput('Real code delivery/payment providers are still unconfigured. These checks do not certify checkout readiness.')
            ->assertSuccessful();
        config(['cors.allowed_origins' => ['*']]);
        $this->artisan('security:check')->assertFailed();
    }
}
