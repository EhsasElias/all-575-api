<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Services\CartService;
use App\Domain\Catalog\Services\Availability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MergeCartRequest;
use App\Http\Requests\Api\V1\SetCartItemRequest;
use App\Http\Resources\CartResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    /** Optional `address_id` adds per-line availability for that address's delivery zone. */
    public function show(Request $request, Availability $availability): CartResource
    {
        return $this->present($request, $availability);
    }

    public function setItem(SetCartItemRequest $request, int $variant, Availability $availability): CartResource
    {
        $this->cart->setQuantity($request->user(), $variant, $request->integer('quantity'));

        return $this->present($request, $availability);
    }

    public function merge(MergeCartRequest $request, Availability $availability): CartResource
    {
        $this->cart->merge($request->user(), $request->validated('items'));

        return $this->present($request, $availability);
    }

    public function clear(Request $request): Response
    {
        $this->cart->clear($request->user());

        return response()->noContent();
    }

    private function present(Request $request, Availability $availability): CartResource
    {
        $cart = Cart::forUser($request->user());
        $cart->load('items.variant.product');

        $units = null;

        if ($request->filled('address_id')) {
            $zoneId = $request->user()->addresses()->findOrFail($request->integer('address_id'))->service_zone_id;
            $units = $zoneId === null ? null : $availability->forVariants($cart->items->pluck('variant_id')->all(), $zoneId);
        }

        return new CartResource($cart, $units);
    }
}
