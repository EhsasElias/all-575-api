<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $length = (int) config('store.otp.length');

        return [
            'challenge_id' => ['required', 'string', 'size:26'],
            'code' => ['required', 'string', "digits:{$length}"],
            'device_name' => ['nullable', 'string', 'max:100'],
            'consents' => ['nullable', 'array'],
            'consents.terms_version' => ['nullable', 'string', 'max:20'],
            'consents.privacy_version' => ['nullable', 'string', 'max:20'],
            'consents.marketing' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{terms_version?: string, privacy_version?: string, marketing?: bool}|null */
    public function consents(): ?array
    {
        $consents = $this->validated('consents');

        return is_array($consents) ? array_filter($consents, fn (mixed $v): bool => $v !== null) : null;
    }
}
