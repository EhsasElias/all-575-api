<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // Anything tied to a signed-in customer must never be stored by a shared cache or the browser history.
        if ($request->user() !== null || $request->bearerToken() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
