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
        // Geoapify's free tier is optional; its API key stays on the Laravel server.
        // If it is unavailable, the configured Nominatim-compatible providers are attempted.
        $provider = config('services.geocoding.provider', 'nominatim');
        $geoapifyKey = config('services.geocoding.geoapify_key');
        if ($provider === 'geoapify' && is_string($geoapifyKey) && $geoapifyKey !== '') {
            $geoapify = $this->geoapifyLookup($lat, $lng, $language, $geoapifyKey);
            if ($geoapify !== null) {
                return $geoapify;
            }
        }

        $primary = config('services.geocoding.url');
        $fallback = config('services.geocoding.fallback_url');
        $endpoints = array_values(array_unique(array_filter(
            [$primary, $fallback],
            static fn ($url): bool => is_string($url) && str_starts_with($url, 'https://')
        )));
        abort_if($endpoints === [], 503, 'Address lookup unavailable.');

        $point = ['lat' => round($lat, 5), 'lon' => round($lng, 5)];
        $key = 'address-lookup:'.hash('sha256', json_encode([$endpoints, $point, $language]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        // One shared request gate per endpoint, across customers and app instances.
        // The public Nominatim service must not be called more than once per second.
        foreach ($endpoints as $endpoint) {
            $gate = 'address-lookup:provider-gate:'.hash('sha256', $endpoint);
            if (! Cache::add($gate, true, 2)) {
                // Preserve the existing 429 contract for the primary-only configuration.
                if (count($endpoints) === 1) {
                    abort(429, 'Try address lookup again shortly.');
                }
                continue;
            }

            try {
                $response = Http::acceptJson()
                    ->withUserAgent('ALL575/1.0 ('.config('app.url').')')
                    ->connectTimeout(2)->timeout(5)->get($endpoint, [
                        ...$point, 'format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => $language,
                    ]);
            } catch (ConnectionException) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $raw = $response->json('address');
            if (! is_array($raw)) {
                continue;
            }

            $fields = $this->fields($raw);
            Cache::put($key, $fields, now()->addDay());

            return $fields;
        }

        abort(503, 'Address lookup unavailable.');
    }

    /**
     * Paid plans are not required for low-volume testing; availability and quota
     * depend on the provider's current free-tier policy. Never send the API key to browsers.
     */
    private function geoapifyLookup(float $lat, float $lng, string $language, string $apiKey): ?array
    {
        $endpoint = 'https://api.geoapify.com/v1/geocode/reverse';
        $point = ['lat' => round($lat, 5), 'lon' => round($lng, 5)];
        $key = 'address-lookup:geoapify:'.hash('sha256', json_encode([$point, $language]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        // A separate gate protects the free quota against bursts.
        if (! Cache::add('address-lookup:geoapify-gate', true, 1)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withUserAgent('ALL575/1.0 ('.config('app.url').')')
                ->connectTimeout(2)->timeout(5)->get($endpoint, [
                    ...$point,
                    'apiKey' => $apiKey,
                    'lang' => in_array($language, ['ar', 'en', 'fr'], true) ? $language : 'en',
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $properties = $response->json('features.0.properties');
        if (! is_array($properties)) {
            return null;
        }

        $raw = [
            'country_code' => $properties['country_code'] ?? null,
            'state' => $properties['state'] ?? null,
            'city' => $properties['city'] ?? null,
            'suburb' => $properties['suburb'] ?? ($properties['district'] ?? null),
            'road' => $properties['street'] ?? null,
            'house_number' => $properties['housenumber'] ?? null,
            'postcode' => $properties['postcode'] ?? null,
        ];
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
