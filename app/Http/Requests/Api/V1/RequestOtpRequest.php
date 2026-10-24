<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Identity\Enums\OtpChannel;
use App\Support\SaudiPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestOtpRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(OtpChannel::class)],
            'destination' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->normalizedDestination() === null) {
                    $fail($this->channel() === OtpChannel::Phone
                        ? 'Enter a valid Saudi mobile number.'
                        : 'Enter a valid email address.');
                }
            }],
        ];
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::from((string) $this->input('channel'));
    }

    public function normalizedDestination(): ?string
    {
        $raw = (string) $this->input('destination');

        return match (OtpChannel::tryFrom((string) $this->input('channel'))) {
            OtpChannel::Phone => SaudiPhone::normalize($raw),
            OtpChannel::Email => filter_var(strtolower(trim($raw)), FILTER_VALIDATE_EMAIL) ?: null,
            null => null,
        };
    }
}
