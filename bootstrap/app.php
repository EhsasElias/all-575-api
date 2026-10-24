<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiLocale;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'active' => EnsureActiveUser::class,
        ]);
        $middleware->api(prepend: [SetApiLocale::class], append: [SecurityHeaders::class]);

        // Behind a load balancer the client address comes from X-Forwarded-For, and rate limits are keyed by it.
        // Trust nothing by default; list the proxy addresses (or '*' if the platform guarantees the edge) in TRUSTED_PROXIES.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }
        $middleware->throttleApi();
        // An API has no login page to send guests to: answer 401 instead of redirecting.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(function (DomainRuleViolation $e) {
            $headers = isset($e->meta['retry_after']) ? ['Retry-After' => (string) $e->meta['retry_after']] : [];

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->meta, $e->status, $headers);
        });
    })->create();
