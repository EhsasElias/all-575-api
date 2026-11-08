<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Normalizes seller-supplied cover images into validated, locally stored files.
 * Never stores a remote URL in products.image_path.
 */
final class SellerProductImageSource
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public static function storeBytes(string $bytes, int $sellerId): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['image' => ['Image must not exceed 5 MB.']]);
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($ext === null) {
            throw ValidationException::withMessages(['image' => ['Use a valid JPEG, PNG or WebP image.']]);
        }
        $path = 'products/'.$sellerId.'/'.bin2hex(random_bytes(16)).'.'.$ext;
        if (!Storage::disk('public')->put($path, $bytes)) {
            throw ValidationException::withMessages(['image' => ['Could not save product image.']]);
        }
        return $path;
    }

    /**
     * Follow a small number of HTTP redirects manually, validating and pinning
     * the public DNS address of every destination (never CURLOPT_FOLLOWLOCATION).
     * This supports image CDNs and Wikimedia Special:FilePath safely.
     */
    public static function storeUrl(string $url, int $sellerId): string
    {
        $url = trim($url);
        for ($hop = 0; $hop <= 4; ++$hop) {
            [$status, $body, $location] = self::downloadPublicImageUrl($url);
            if ($status === 200) {
                return self::storeBytes($body, $sellerId);
            }
            if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === null || $hop === 4) {
                throw ValidationException::withMessages(['image_url' => [
                    'Image URL did not return an image (HTTP '.$status.'). Use a direct public HTTPS image URL.',
                ]]);
            }
            $url = self::redirectDestination($url, $location);
        }
        throw ValidationException::withMessages(['image_url' => ['Too many image URL redirects.']]);
    }

    private static function redirectDestination(string $current, string $location): string
    {
        $location = trim($location);
        if ($location === '' || strlen($location) > 2048 ||
            preg_match('/[\\x00-\\x1F\\x7F]/', $location)) {
            throw ValidationException::withMessages(['image_url' => ['Invalid image redirect URL.']]);
        }
        // Permit HTTPS absolute, scheme-relative, and root-relative redirects.
        if (str_starts_with($location, 'https://')) return $location;
        $parts = parse_url($current);
        $origin = 'https://'.($parts['host'] ?? '');
        if (str_starts_with($location, '//')) return 'https:'.$location;
        if (str_starts_with($location, '/')) return $origin.$location;
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $location)) {
            throw ValidationException::withMessages(['image_url' => ['Only HTTPS image redirects are allowed.']]);
        }
        $path = (string) ($parts['path'] ?? '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $origin.$directory.$location;
    }

    /** @return array{int, string, ?string} */
    private static function downloadPublicImageUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['fragment']) || isset($parts['port']) ||
            strlen($url) > 2048) {
            throw ValidationException::withMessages(['image_url' => [
                'Use a public HTTPS image URL without a custom port or credentials.',
            ]]);
        }
        $host = (string) $parts['host'];
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) ||
            !str_contains($host, '.') || str_ends_with(strtolower($host), '.local')) {
            throw ValidationException::withMessages(['image_url' => ['Invalid public image host.']]);
        }

        // Validate every DNS address, including IPv6; fail closed on internal
        // addresses and pin the vetted address to stop DNS rebinding.
        $dns = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($dns ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $ips[] = $ip;
        }
        if ($ips === [] || count($ips) > 20 ||
            array_filter($ips, static fn (string $ip): bool =>
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            ) !== []) {
            throw ValidationException::withMessages(['image_url' => [
                'Image host must resolve only to public IP addresses.',
            ]]);
        }
        if (!function_exists('curl_init')) {
            throw ValidationException::withMessages(['image_url' => ['The server image downloader is unavailable.']]);
        }

        $chosen = $ips[0];
        $resolve = $host.':443:'.(str_contains($chosen, ':') ? '['.$chosen.']' : $chosen);
        $body = '';
        $location = null;
        $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => 'ALL575-ProductImageImporter/1.1',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, strlen('Location:')));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
        }
        if ($tooLarge) {
            throw ValidationException::withMessages(['image_url' => ['Image must not exceed 5 MB.']]);
        }
        if ($ok === false) {
            throw ValidationException::withMessages(['image_url' => [
                'Could not download image. Check that this public HTTPS URL is reachable from the server.',
            ]]);
        }
        return [$status, $body, $location];
    }

}
