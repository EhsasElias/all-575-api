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

    /** Download only from public internet IPs; never follow redirects. */
    public static function storeUrl(string $url, int $sellerId): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['fragment']) || isset($parts['port']) ||
            strlen($url) > 2048) {
            throw ValidationException::withMessages(['image_url' => ['Use a public HTTPS image URL without a custom port or credentials.']]);
        }
        $host = (string) $parts['host'];
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) ||
            !str_contains($host, '.') || str_ends_with(strtolower($host), '.local')) {
            throw ValidationException::withMessages(['image_url' => ['Invalid public image host.']]);
        }

        // Pin a verified DNS address to the connection to prevent DNS rebinding.
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
            throw ValidationException::withMessages(['image_url' => ['Image host must resolve only to public IP addresses.']]);
        }
        if (!function_exists('curl_init')) {
            throw ValidationException::withMessages(['image_url' => ['The server image downloader is unavailable.']]);
        }

        $chosen = $ips[0];
        $resolve = $host.':443:'.(str_contains($chosen, ':') ? '['.$chosen.']' : $chosen);
        $body = '';
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
            CURLOPT_USERAGENT => 'ALL575-ProductImageImporter/1.0',
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
        if ($tooLarge || $ok === false || $status !== 200) {
            throw ValidationException::withMessages(['image_url' => ['Could not download image (HTTPS 200 and maximum 5 MB required).']]);
        }
        return self::storeBytes($body, $sellerId);
    }
}
