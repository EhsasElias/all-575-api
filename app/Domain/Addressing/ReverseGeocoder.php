<?php

namespace App\Domain\Addressing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ReverseGeocoder
{
    /** Only coordinates/language are shared with the provider, never account or form data. */
    public function lookup(float $lat, float $lng, string $language): array
    {
        $endpoint = config('services.geocoding.url');
        abort_unless(is_string($endpoint) && $endpoint !== '', 503, 'Address lookup unavailable.');
        $point = ['lat' => round($lat, 5), 'lon' => round($lng, 5)];
        $key = 'address-lookup:'.hash('sha256', $endpoint.json_encode([$point, $language]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        // App-wide, not per customer: stay below the public provider's 1 request/second limit.
        // Production instances must share the cache store to share this gate and results.
        abort_unless(Cache::add('address-lookup:provider-gate', true, 2), 429, 'Try address lookup again shortly.');
        try {
            $response = Http::acceptJson()
                ->withUserAgent('ALL575/1.0 ('.config('app.url').')')
                ->connectTimeout(2)->timeout(5)->get($endpoint, [
                    ...$point, 'format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => $language,
                ]);
        } catch (ConnectionException) {
            abort(503, 'Address lookup unavailable.');
        }
        abort_unless($response->successful(), 503, 'Address lookup unavailable.');
        $raw = $response->json('address');
        abort_unless(is_array($raw), 503, 'Address lookup unavailable.');
        $fields = $this->fields($raw);
        Cache::put($key, $fields, now()->addDay());

        return $fields;
    }

    private function fields(array $address): array
    {
        $country = is_string($address['country_code'] ?? null) ? strtolower($address['country_code']) : null;
        $saudi = $country === 'sa';
        $text = static function (array $keys, int $limit = 80) use ($address, $saudi): ?string {
            if (! $saudi) {
                return null;
            }
            foreach ($keys as $key) {
                $value = $address[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return mb_substr(trim($value), 0, $limit);
                }
            }

            return null;
        };
        $building = $text(['house_number']);
        $postal = $text(['postcode']);

        return [
            'country_code' => $country,
            'region' => $text(['state', 'region']),
            'city' => $text(['city', 'town', 'village', 'municipality']),
            'district' => $text(['suburb', 'neighbourhood', 'quarter', 'city_district', 'district']),
            'street' => $text(['road', 'pedestrian', 'residential'], 120),
            'building_number' => $building !== null && preg_match('/^[0-9]{4}$/D', $building) ? $building : null,
            'postal_code' => $postal !== null && preg_match('/^[0-9]{5}$/D', $postal) ? $postal : null,
        ];
    }
}
