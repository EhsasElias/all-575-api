<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Actions\VerifyOtp;
use App\Domain\Identity\Enums\OtpChannel;
use App\Domain\Identity\Models\OtpChallenge;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/** One-time codes under parallel requests. A real race cannot be played inside one test, so each half of the defence is checked on its own. */
class OtpRaceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+966512345678';

    public function test_while_a_code_is_being_sent_to_a_number_another_request_for_it_is_turned_away(): void
    {
        $lock = Cache::lock('otp-request:'.hash('sha256', 'phone|'.self::PHONE), 15);
        $this->assertTrue($lock->get());

        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => '0512345678'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'otp_cooldown');

        $this->assertSame([], $this->otp->sent);

        $lock->release();
        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => '0512345678'])->assertAccepted();
        $this->assertCount(1, $this->otp->sent);
    }

    public function test_the_lock_is_released_when_sending_fails_so_the_person_can_try_again(): void
    {
        $this->otp->failNext = true;

        try {
            app(RequestOtp::class)->handle(OtpChannel::Phone, self::PHONE, 'en', null);
            $this->fail('The failing sender should have thrown.');
        } catch (\Throwable) {
            // expected
        }

        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => '0512345678'])->assertAccepted();
    }

    public function test_a_code_that_another_request_has_already_used_cannot_complete_a_second_sign_in(): void
    {
        $challenge = OtpChallenge::query()->create([
            'channel' => 'phone', 'destination' => self::PHONE, 'code_hash' => RequestOtp::hash('123456'),
            'max_attempts' => 5, 'expires_at' => now()->addMinutes(5), 'ip_address' => null,
        ]);
        $challenge->forceFill(['consumed_at' => now()])->save();

        $complete = new ReflectionMethod(VerifyOtp::class, 'completeLogin');

        try {
            $complete->invoke(app(VerifyOtp::class), $challenge->id, 'test device', 'en', null, null);
            $this->fail('A spent code must not sign anybody in.');
        } catch (DomainRuleViolation $e) {
            $this->assertSame('otp_expired', $e->errorCode);
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
