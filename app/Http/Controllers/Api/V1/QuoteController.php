<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\Services\QuoteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateQuoteRequest;
use Illuminate\Http\JsonResponse;

class QuoteController extends Controller
{
    public function store(CreateQuoteRequest $request, QuoteService $quotes): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($request->integer('address_id'));
        $quote = $quotes->create($request->user(), $address, $request->input('promo_code'));

        return response()->json(['data' => ['id' => $quote->id, 'expires_at' => $quote->expires_at] + $quote->payload], 201);
    }
}
