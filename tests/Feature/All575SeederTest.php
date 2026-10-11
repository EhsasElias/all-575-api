<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\All575Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class All575SeederTest extends TestCase
{
    use RefreshDatabase;

    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'seller', 'customer'] as $role) {
            config(["all575.accounts.{$role}" => ['email' => '', 'password' => '']]);
            foreach (['EMAIL', 'PASSWORD'] as $field) {
                $key = 'ALL575_'.strtoupper($role).'_'.$field;
                $this->savedEnvironment[$key] = getenv($key);
                putenv($key);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }

        parent::tearDown();
    }

    public function test_it_creates_all_roles_and_preserves_accounts_on_rerun(): void
    {
        $this->seed(All575Seeder::class);
        $passwords = User::pluck('password', 'email')->all();

        foreach (['admin', 'seller', 'customer'] as $role) {
            $user = User::where('email', "{$role}@all575.test")->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->is_active);
            $this->assertTrue(Hash::check('All575Demo!2026', $user->password));
        }

        $seller = User::where('role', 'seller')->firstOrFail()->seller;
        $this->assertSame('demo-seller-1', $seller->slug);
        $this->assertSame('approved', $seller->status);
        $this->assertTrue((bool) $seller->is_active);

        $this->seed(All575Seeder::class);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('sellers', 4);
        $this->assertSame($passwords, User::pluck('password', 'email')->all());
    }

    public function test_seeded_accounts_have_the_expected_route_access(): void
    {
        $this->seed(All575Seeder::class);

        Sanctum::actingAs(User::where('role', 'customer')->firstOrFail());
        $this->getJson('/api/v1/admin/products')->assertForbidden();
        $this->getJson('/api/v1/seller/profile')->assertForbidden();

        Sanctum::actingAs(User::where('role', 'seller')->firstOrFail());
        $this->getJson('/api/v1/seller/profile')->assertOk();
        $this->getJson('/api/v1/admin/products')->assertForbidden();

        Sanctum::actingAs(User::where('role', 'admin')->firstOrFail());
        $this->getJson('/api/v1/admin/products')->assertOk();
    }

    public function test_production_requires_configured_credentials_for_each_role(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('db:seed', ['--class' => All575Seeder::class, '--force' => true])->assertExitCode(0);
        $this->assertDatabaseCount('users', 0);

        foreach (['admin', 'seller', 'customer'] as $role) {
            config(["all575.accounts.{$role}" => [
                'email' => "{$role}@example.com",
                'password' => 'ConfiguredPass!2026',
            ]]);
        }

        $this->artisan('db:seed', ['--class' => All575Seeder::class, '--force' => true])->assertExitCode(0);
        $this->assertDatabaseCount('users', 3);
        foreach (['admin', 'seller', 'customer'] as $role) {
            $this->assertDatabaseHas('users', ['email' => "{$role}@example.com", 'role' => $role]);
        }
    }

    public function test_an_existing_email_with_another_role_is_not_promoted(): void
    {
        User::factory()->create(['email' => 'admin@all575.test', 'role' => 'customer']);

        try {
            $this->seed(All575Seeder::class);
            $this->fail('Expected a conflicting role to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('another role', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['email' => 'admin@all575.test', 'role' => 'customer']);
        $this->assertSame(0, DB::table('sellers')->count());
    }
}
