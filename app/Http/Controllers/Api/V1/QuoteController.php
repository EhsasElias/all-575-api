<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\Services\QuoteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateQuoteRequest;
use Illuminate\Http\JsonResponse;

class QuoteController extends Controller
{
    /**
     * Price the cart for an address. Prices and VAT come from the server; the quote expires after a few minutes.
     *
     * @status 201
     *
     * @response array{data: array{id: string, expires_at: string} & array{currency: string, lines: list<array{variant_id: int, product_id: int, seller_id: int, product_slug: string, name: array{ar: ?string, en: ?string}, variant_name: ?array{ar: ?string, en: ?string}, image_url: ?string, quantity: int, unit_price: int, line_price: int, discount: int, line_total: int, vat_class: string, vat_rate_basis_points: int, available: bool, available_units: int}>, subtotal: int, discount: int, delivery_fee: int, total: int, vat_included: int, vat: list<array{vat_class: string, rate_basis_points: int, taxable_net: int, vat: int, gross: int}>, promo_code: ?string, address_id: int, zone_id: int, issues: list<array{variant_id: int, code: string, available_units: int}>, can_checkout: bool}}
     */
    public function store(CreateQuoteRequest $request, QuoteService $quotes): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($request->integer('address_id'));
        $quote = $quotes->create($request->user(), $address, $request->input('promo_code'));

        return response()->json(['data' => ['id' => $quote->id, 'expires_at' => $quote->expires_at] + $quote->payload], 201);
    }
}
