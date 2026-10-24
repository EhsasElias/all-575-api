<?php

namespace App\Http\Resources\Concerns;

trait PublicAssets
{
    protected function assetUrl(?string $path): ?string
    {
        return $path === null ? null : asset($path);
    }
}
