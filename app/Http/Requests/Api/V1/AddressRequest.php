<?php

namespace App\Http\Requests\Api\V1;

use App\Support\SaudiPhone;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (SaudiPhone::normalize((string) $value) === null) {
                    $fail('Enter a valid Saudi mobile number.');
                }
            }],
            'region' => ['required', 'string', 'max:80'],
            'city' => ['required', 'string', 'max:80'],
            'district' => ['required', 'string', 'max:80'],
            'street' => ['required', 'string', 'max:120'],
            // Saudi National Address (SPL): building and additional number are 4 digits, postal code 5,
            // short address is 4 letters followed by 4 digits.
            'building_number' => ['required', 'regex:/^\d{4}$/'],
            'additional_number' => ['required', 'regex:/^\d{4}$/'],
            'postal_code' => ['required', 'regex:/^\d{5}$/'],
            'short_address' => ['nullable', 'regex:/^[A-Za-z]{4}\d{4}$/'],
            'unit' => ['nullable', 'string', 'max:20'],
            'floor' => ['nullable', 'string', 'max:20'],
            'delivery_notes' => ['nullable', 'string', 'max:300'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function addressData(): array
    {
        $data = $this->validated();
        $data['recipient_phone'] = SaudiPhone::normalize($data['recipient_phone']);

        if (! empty($data['short_address'])) {
            $data['short_address'] = strtoupper($data['short_address']);
        }

        return $data;
    }
}
