<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Services\FirebaseIdTokenVerifier;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirebaseIdTokenVerifierTest extends TestCase
{
    /** @var \OpenSSLAsymmetricKey */
    private $key;

    /** @var string */
    private string $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.firebase.project_id', 'market575-4eccd');
        Cache::forget('firebase_securetoken_public_certificates');
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'firebase.test'], $this->key);
        $cert = openssl_csr_sign($csr, null, $this->key, 1);
        openssl_x509_export($cert, $this->certificate);
        Http::fake([
            'www.googleapis.com/robot/v1/metadata/x509/*' => Http::response(['test-key-id' => $this->certificate]),
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function token(array $changes = [], string $algorithm = 'RS256'): string
    {
        $now = time();
        $header = ['alg' => $algorithm, 'kid' => 'test-key-id', 'typ' => 'JWT'];
        $claims = array_replace([
            'aud' => 'market575-4eccd',
            'iss' => 'https://securetoken.google.com/market575-4eccd',
            'sub' => 'firebase-uid-test',
            'iat' => $now - 60,
            'auth_time' => $now - 120,
            'exp' => $now + 3600,
            'email' => 'customer@example.com',
            'email_verified' => true,
            'firebase' => ['sign_in_provider' => 'password'],
        ], $changes);

        $base64url = static fn (string $input): string => rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
        $signed = $base64url(json_encode($header, JSON_THROW_ON_ERROR)).'.'.$base64url(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($signed, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $signed.'.'.$base64url($signature);
    }

    public function test_accepts_a_signed_firebase_email_password_token(): void
    {
        $verified = app(FirebaseIdTokenVerifier::class)->verify($this->token());
        $this->assertSame(['uid' => 'firebase-uid-test', 'email' => 'customer@example.com'], $verified);
    }

    public function test_rejects_forged_or_modified_signatures(): void
    {
        $token = $this->token();
        $tampered = substr($token, 0, -2).'ab';

        $this->expectException(DomainRuleViolation::class);
        app(FirebaseIdTokenVerifier::class)->verify($tampered);
    }

    public function test_rejects_tokens_from_another_project(): void
    {
        $this->expectException(DomainRuleViolation::class);
        app(FirebaseIdTokenVerifier::class)->verify($this->token(['aud' => 'attacker-project']));
    }

    public function test_rejects_unverified_emails(): void
    {
        $this->expectException(DomainRuleViolation::class);
        app(FirebaseIdTokenVerifier::class)->verify($this->token(['email_verified' => false]));
    }

    public function test_rejects_expired_tokens(): void
    {
        $this->expectException(DomainRuleViolation::class);
        app(FirebaseIdTokenVerifier::class)->verify($this->token(['exp' => time() - 1]));
    }

    public function test_rejects_phone_tokens_as_email_password_proof(): void
    {
        $this->expectException(DomainRuleViolation::class);
        app(FirebaseIdTokenVerifier::class)->verify($this->token(['firebase' => ['sign_in_provider' => 'phone']]));
    }
}
