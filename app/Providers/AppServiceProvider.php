<?php

namespace App\Providers;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Services\LogOtpSender;
use App\Domain\Identity\Services\UnconfiguredOtpSender;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\UnconfiguredGateway;
use App\Domain\Pricing\VatCalculator;
use App\Support\SaudiPhone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpSender::class, fn (): OtpSender => match (config('store.otp.driver')) {
            'log' => $this->guardLogDriver(),
            default => new UnconfiguredOtpSender,
        });

        $this->app->bind(PaymentGateway::class, fn (): PaymentGateway => match (config('store.payments.driver')) {
            'fake' => $this->guardFakeGateway(),
            default => new UnconfiguredGateway,
        });

        $this->app->singleton(VatCalculator::class, fn (): VatCalculator => new VatCalculator(
            (int) config('store.vat_rate_basis_points'),
        ));
    }

    public function boot(): void
    {
        // Catches lazy loading (N+1), silently discarded attributes and missing attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceHttps();
        }

        $this->configureRateLimiting();
    }

    /** 0512345678 and +966512345678 are the same phone, so they must share one rate-limit bucket. */
    private function canonicalDestination(string $destination): string
    {
        return SaudiPhone::normalize($destination) ?? strtolower(trim($destination));
    }

    private function guardFakeGateway(): PaymentGateway
    {
        if ($this->app->isProduction()) {
            throw new LogicException('The fake payment gateway must never run in production.');
        }

        return new FakeGateway((string) config('store.payments.fake_webhook_secret'));
    }

    private function guardLogDriver(): OtpSender
    {
        if ($this->app->isProduction()) {
            throw new LogicException('The log OTP driver must never run in production.');
        }

        return new LogOtpSender;
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('quotes', fn (Request $request): array => [
            Limit::perMinute(30)->by('quotes:'.$request->user()?->id),
            // Each guess at a promo code counts extra, so the code space cannot be brute-forced.
            ...($request->filled('promo_code') ? [Limit::perMinute(10)->by('promo:'.$request->user()?->id)] : []),
        ]);

        RateLimiter::for('checkout', fn (Request $request): Limit => Limit::perMinute(10)->by('checkout:'.$request->user()?->id));

        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(300)->by('webhooks:'.$request->ip()));

        RateLimiter::for('otp-request', fn (Request $request): array => [
            Limit::perMinute(10)->by('otp-ip:'.$request->ip()),
            Limit::perHour(5)->by('otp-destination:'.$this->canonicalDestination((string) $request->input('destination'))),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request): array => [
            Limit::perMinute(20)->by('otp-verify-ip:'.$request->ip()),
            Limit::perMinute(10)->by('otp-verify-challenge:'.$request->input('challenge_id')),
        ]);
    }
}
