<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderIndexRequest;
use App\Http\Resources\OrderDetailResource;
use App\Http\Resources\OrderResource;
use App\Http\Support\OrderPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(OrderIndexRequest $request): AnonymousResourceCollection
    {
        $query = $request->user()->orders()->with(['items', 'payments']);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->string('q')->trim()->toString());
            $query->whereRaw("number LIKE ? ESCAPE '!'", ['%'.$term.'%']);
        }
        foreach (['from', 'to'] as $edge) {
            if ($request->filled($edge)) {
                $day = CarbonImmutable::parse($request->input($edge), config('store.slots.timezone'));
                $query->where('placed_at', $edge === 'from' ? '>=' : '<=', ($edge === 'from' ? $day->startOfDay() : $day->endOfDay())->utc());
            }
        }
        $orders = $query->latest('placed_at')->latest('id')->cursorPaginate($request->integer('per_page', 20))->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $order, OrderPresenter $presenter): OrderDetailResource
    {
        return new OrderDetailResource($presenter->load($request->user()->orders()->findOrFail($order)));
    }
}
