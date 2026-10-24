<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderDetailResource;
use App\Http\Resources\OrderResource;
use App\Http\Support\OrderPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user()->orders()->with(['items', 'payments'])->latest('placed_at')->latest('id')->cursorPaginate(20);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $order, OrderPresenter $presenter): OrderDetailResource
    {
        return new OrderDetailResource($presenter->load($request->user()->orders()->findOrFail($order)));
    }
}
