<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\OtpChannel;
use App\Domain\Identity\Services\MailOtpSender;
use App\Domain\Identity\Services\TaqnyatOtpSender;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TaqnyatOtpSenderTest extends TestCase
{
    public function test_it_posts_a_saudi_mobile_otp_to_the_configured_gateway(): void
    {
        config()->set('store.otp.taqnyat', ['token' => 'test-bearer', 'sender' => 'TEST']);
        Http::fake(['api.taqnyat.sa/v1/messages' => Http::response([
            'statusCode' => 201, 'accepted' => '[966512345678,]', 'rejected' => '[]',
        ], 201)]);

        app(TaqnyatOtpSender::class)->send(OtpChannel::Phone, '+966512345678', '123456', 'ar');

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.taqnyat.sa/v1/messages'
            && $request['recipients'] === ['966512345678']
            && $request['sender'] === 'TEST'
            && str_contains((string) $request['body'], '123456')
        );
    }

    public function test_it_refuses_sending_when_credentials_are_missing(): void
    {
        config()->set('store.otp.taqnyat', ['token' => '', 'sender' => '']);
        Http::preventStrayRequests();
        $this->expectException(DomainRuleViolation::class);
        app(TaqnyatOtpSender::class)->send(OtpChannel::Phone, '+966512345678', '123456', 'en');
    }

    public function test_it_refuses_gateway_responses_without_accepted_recipient(): void
    {
        config()->set('store.otp.taqnyat', ['token' => 'test-bearer', 'sender' => 'TEST']);
        Http::fake(['api.taqnyat.sa/v1/messages' => Http::response([
            'statusCode' => 201, 'accepted' => '[]',
        ], 201)]);
        $this->expectException(DomainRuleViolation::class);
        app(TaqnyatOtpSender::class)->send(OtpChannel::Phone, '+966512345678', '123456', 'en');
    }
}
