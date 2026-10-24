<?php

namespace Tests;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeOtpSender;

abstract class TestCase extends BaseTestCase
{
    protected FakeOtpSender $otp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otp = new FakeOtpSender;
        $this->app->instance(OtpSender::class, $this->otp);
    }

    /** A verified customer, authenticated through Sanctum with the customer ability. */
    public function actingAsCustomer(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user, ['customer']);

        return $user;
    }
}
