<?php

use App\Http\Middleware\EnsureActiveUser;
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
        $middleware->api(prepend: [SetApiLocale::class]);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(function (DomainRuleViolation $e) {
            $headers = isset($e->meta['retry_after']) ? ['Retry-After' => (string) $e->meta['retry_after']] : [];

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->meta, $e->status, $headers);
        });
    })->create();
