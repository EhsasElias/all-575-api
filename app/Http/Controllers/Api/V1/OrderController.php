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
        $escape = static fn (string $text): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($text));
        if ($request->filled('product')) {
            $term = $escape($request->string('product')->toString());
            $query->whereHas('items', fn ($items) => $items->where(function ($names) use ($term) {
                $names->whereRaw("name_ar LIKE ? ESCAPE '!'", ['%'.$term.'%'])
                    ->orWhereRaw("name_en LIKE ? ESCAPE '!'", ['%'.$term.'%']);
            }));
        }
        if ($request->filled('seller')) {
            $term = $escape($request->string('seller')->toString());
            $query->whereHas('suborders.seller', fn ($seller) => $seller->where(function ($names) use ($term) {
                $names->whereRaw("name_ar LIKE ? ESCAPE '!'", ['%'.$term.'%'])
                    ->orWhereRaw("name_en LIKE ? ESCAPE '!'", ['%'.$term.'%']);
            }));
        }
        if ($request->filled('branch')) {
            $term = $escape($request->string('branch')->toString());
            $query->whereHas('suborders.branch', fn ($branch) => $branch->where(function ($names) use ($term) {
                $names->whereRaw("name_ar LIKE ? ESCAPE '!'", ['%'.$term.'%'])
                    ->orWhereRaw("name_en LIKE ? ESCAPE '!'", ['%'.$term.'%']);
            }));
        }
        if ($request->filled('payment_method')) {
            $query->whereHas('payments', fn ($payments) =>
                $payments->where('method', $request->input('payment_method')));
        }
        if ($request->filled('min_price')) {
            $query->where('total_halalas', '>=', (int) round(((float) $request->input('min_price')) * 100));
        }
        if ($request->filled('max_price')) {
            $query->where('total_halalas', '<=', (int) round(((float) $request->input('max_price')) * 100));
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
