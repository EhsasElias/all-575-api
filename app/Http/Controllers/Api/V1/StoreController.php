<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\VatCalculator;
use App\Domain\Pricing\VatClass;
use App\Http\Controllers\Controller;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

class StoreController extends Controller
{
    /**
     * Platform constants the app shows or sends back: the one price, the VAT rate and the current legal text versions.
     *
     * @response array{data: array{currency: string, price: array{gross: int, net: int, vat: int}, vat_rate_basis_points: int, terms_version: string, privacy_version: string}}
     */
    public function show(VatCalculator $vat): JsonResponse
    {
        $rate = $vat->rateFor(VatClass::Standard);
        $split = $vat->splitGross(Money::halalas((int) config('store.price_halalas')), $rate);

        return response()->json(['data' => [
            'currency' => config('store.currency'),
            'price' => ['gross' => $split->gross->halalas, 'net' => $split->net->halalas, 'vat' => $split->vat->halalas],
            'vat_rate_basis_points' => $rate,
            'terms_version' => config('store.terms_version'),
            'privacy_version' => config('store.privacy_version'),
        ]]);
    }
}
