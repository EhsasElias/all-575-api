<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Support\Facades\Storage;

trait PublicAssets
{
    protected function assetUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // Seller uploads and Excel imports are written to the public disk in
        // products/{seller_id}/...; they must resolve via /storage/products/...
        // rather than /products/..., which does not serve uploaded files.
        if (str_starts_with(ltrim($path, '/'), 'products/')) {
            return asset(Storage::disk('public')->url(ltrim($path, '/')));
        }

        // Keep seeded catalogue images and other existing public asset URLs.
        return asset($path);
    }
}
