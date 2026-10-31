<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class FirebaseLoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:12000'],
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
        $value = $this->validated('consents');

        return is_array($value) ? $value : null;
    }
}
