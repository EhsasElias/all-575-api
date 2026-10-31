<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verify Firebase Auth ID tokens against Google's signing certificates.
 * No Firebase service-account key or client-supplied public key is trusted.
 */
class FirebaseIdTokenVerifier
{
    /** @return array{uid: string, email: string} */
    public function verify(string $token): array
    {
        $projectId = (string) config('services.firebase.project_id');
        if ($projectId === '') {
            throw new DomainRuleViolation('firebase_unavailable', 'Firebase authentication is not configured.', 503);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 12000) {
            throw $this->invalid();
        }

        $header = $this->decodeSegment($parts[0]);
        $claims = $this->decodeSegment($parts[1]);
        $signature = $this->decodeBase64Url($parts[2]);

        $kid = $header['kid'] ?? null;
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($kid) || $kid === '' || $signature === false) {
            throw $this->invalid();
        }

        $certificates = $this->certificates();
        if (! isset($certificates[$kid]) || ! is_string($certificates[$kid])) {
            throw $this->invalid();
        }

        $verified = openssl_verify($parts[0].'.'.$parts[1], $signature, $certificates[$kid], OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw $this->invalid();
        }

        $now = time();
        $uid = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        $firebase = $claims['firebase'] ?? null;
        if (($claims['aud'] ?? null) !== $projectId
            || ($claims['iss'] ?? null) !== 'https://securetoken.google.com/'.$projectId
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now
            || ! is_int($claims['auth_time'] ?? null) || $claims['auth_time'] > $now
            || ! is_string($uid) || $uid === '' || strlen($uid) > 128
            || ! is_string($email) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ($claims['email_verified'] ?? null) !== true
            || ! is_array($firebase) || ($firebase['sign_in_provider'] ?? null) !== 'password') {
            throw $this->invalid();
        }

        return ['uid' => $uid, 'email' => strtolower(trim($email))];
    }

    /** @return array<string, mixed> */
    private function decodeSegment(string $segment): array
    {
        $decoded = $this->decodeBase64Url($segment);
        if ($decoded === false) {
            throw $this->invalid();
        }
        $json = json_decode($decoded, true);

        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalid();
        }

        return $json;
    }

    private function decodeBase64Url(string $segment): string|false
    {
        if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $segment) !== 1) {
            return false;
        }

        return base64_decode(strtr($segment, '-_', '+/'), true);
    }

    /** @return array<string, string> */
    private function certificates(): array
    {
        try {
            return Cache::remember('firebase_securetoken_public_certificates', now()->addMinutes(30), function (): array {
                $response = Http::timeout(5)->get('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
                if (! $response->successful()) {
                    throw new \RuntimeException('Firebase signing certificates unavailable');
                }
                $certificates = $response->json();
                if (! is_array($certificates) || $certificates === []) {
                    throw new \RuntimeException('Invalid Firebase signing certificate response');
                }

                return $certificates;
            });
        } catch (Throwable) {
            throw new DomainRuleViolation('firebase_unavailable', 'Could not verify authentication right now.', 503);
        }
    }

    private function invalid(): DomainRuleViolation
    {
        return new DomainRuleViolation('firebase_token_invalid', 'Invalid or unverified authentication.', 401);
    }
}
