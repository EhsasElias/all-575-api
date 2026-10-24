<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\WelcomeSlide;
use App\Http\Controllers\Controller;
use App\Http\Resources\WelcomeSlideResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WelcomeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WelcomeSlideResource::collection(
            WelcomeSlide::query()->where('is_active', true)->orderBy('position')->limit(3)->get(),
        );
    }
}
