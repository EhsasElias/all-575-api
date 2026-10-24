<?php

namespace Tests\Support;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Enums\OtpChannel;

/** Captures issued codes so tests can complete the login flow. */
final class FakeOtpSender implements OtpSender
{
    /** @var list<array{channel: OtpChannel, destination: string, code: string, locale: string}> */
    public array $sent = [];

    public function send(OtpChannel $channel, string $destination, string $code, string $locale): void
    {
        $this->sent[] = compact('channel', 'destination', 'code', 'locale');
    }

    public function lastCode(): string
    {
        return $this->sent[array_key_last($this->sent)]['code'];
    }
}
