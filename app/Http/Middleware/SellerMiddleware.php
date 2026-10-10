<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SellerMiddleware
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if (!$user || $user->role !== 'seller') {
            return response()->json([
                'message' => 'Seller access required.',
            ], 403);
        }

        $seller = $user->seller;

        if (
            !$seller ||
            $seller->status !== 'approved' ||
            !$seller->is_active
        ) {
            return response()->json([
                'message' => 'Seller account is not active.',
            ], 403);
        }

        $request->attributes->set(
            'seller',
            $seller
        );

        return $next($request);
    }
}
