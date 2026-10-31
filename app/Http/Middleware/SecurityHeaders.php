<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($request, $next($request));
    }

    /** Used by the exception renderer too: 401/422/429/500 responses need the same protections. */
    public static function apply(Request $request, Response $response): Response
    {
        if (! $request->is('api/*')) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // Login answers can contain a new bearer token even before the request is authenticated.
        // Do not cache failed requests, hand-over codes or any signed-in customer's data either.
        if ($request->is('api/v1/auth/*') || $request->user() !== null
            || $request->bearerToken() !== null || $response->getStatusCode() >= 400) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
