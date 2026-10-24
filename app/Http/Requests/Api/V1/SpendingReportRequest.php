<?php

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class SpendingReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function from(): CarbonImmutable
    {
        return $this->filled('from') ? CarbonImmutable::parse($this->string('from')->toString(), config('store.slots.timezone')) : CarbonImmutable::now(config('store.slots.timezone'))->subDays(30);
    }

    public function to(): CarbonImmutable
    {
        return $this->filled('to') ? CarbonImmutable::parse($this->string('to')->toString(), config('store.slots.timezone')) : CarbonImmutable::now(config('store.slots.timezone'));
    }
}
