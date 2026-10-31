<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckProductionSecurity extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Read-only checks for unsafe production configuration (never prints secrets)';

    public function handle(): int
    {
        $checks = [
            'APP_ENV must be production.' => app()->isProduction(),
            'APP_DEBUG must be false.' => config('app.debug') === false,
            'APP_URL must be an HTTPS origin without credentials.' => $this->secureOrigin((string) config('app.url')),
            'APP_KEY must be configured.' => filled(config('app.key')),
            'Log OTP delivery must be disabled.' => config('store.otp.driver') !== 'log',
            'Fake payments must be disabled.' => config('store.payments.driver') !== 'fake',
            'CORS must list explicit HTTPS or Capacitor origins, without patterns or credentials.' => $this->safeCors(),
            'Session expiry must be between 1 and 30 days.' => (int) config('sanctum.expiration') >= 1440
                && (int) config('sanctum.expiration') <= 43200,
            'Rate limits must use a persistent cache.' => config('cache.default') !== 'array'
                && config('cache.default') !== 'null',
        ];

        foreach ($checks as $message => $passed) {
            $passed ? $this->info('PASS: '.$message) : $this->error('FAIL: '.$message);
        }

        if (config('store.otp.driver') === 'unconfigured' || config('store.payments.driver') === 'unconfigured') {
            $this->warn('Real code delivery/payment providers are still unconfigured. These checks do not certify checkout readiness.');
        }

        $this->line('Also verify TLS, Nginx headers, proxy trust, backups and device/provider tests outside this command.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function safeCors(): bool
    {
        $origins = config('cors.allowed_origins', []);
        if ($origins === [] || config('cors.allowed_origins_patterns') !== [] || config('cors.supports_credentials') !== false) {
            return false;
        }

        foreach ($origins as $origin) {
            if ($origin !== 'capacitor://localhost' && ! $this->secureOrigin($origin)) {
                return false;
            }
        }

        return true;
    }

    private function secureOrigin(string $value): bool
    {
        $url = parse_url($value);

        return is_array($url) && ($url['scheme'] ?? '') === 'https'
            && filled($url['host'] ?? null) && ! str_contains($url['host'], '*')
            && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment'])
            && in_array($url['path'] ?? '', ['', '/'], true);
    }
}
