<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Aftercare\Actions\OpenCustomerCase;
use App\Domain\Aftercare\Models\CustomerCase;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\OrderItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateCustomerCaseRequest;
use App\Http\Requests\Api\V1\CustomerCaseMessageRequest;
use App\Http\Resources\CustomerCaseResource;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CustomerCaseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerCaseResource::collection(CustomerCase::query()->where('user_id', $request->user()->id)->with('messages')->latest('id')->cursorPaginate(20));
    }

    /** @response array{data: array{items: list<array{id: int, name: array{ar: string, en: string}, variant_name: array{ar: ?string, en: ?string}, quantity: int, returnable_quantity: int}>}} */
    public function items(Request $request, string $order): JsonResponse
    {
        $owned = $request->user()->orders()->findOrFail($order);
        $delivered = $owned->suborders()->where('status', SuborderStatus::Delivered->value)->pluck('id');
        $requested = CustomerCase::query()->where('order_id', $owned->id)->where('status', '!=', 'rejected')
            ->selectRaw('order_item_id, SUM(quantity) as requested')->groupBy('order_item_id')->pluck('requested', 'order_item_id');

        return response()->json(['data' => ['items' => $owned->items()->get()->map(fn (OrderItem $item): array => [
            'id' => $item->id, 'name' => ['ar' => $item->name_ar, 'en' => $item->name_en],
            'variant_name' => ['ar' => $item->variant_name_ar, 'en' => $item->variant_name_en],
            'quantity' => $item->quantity,
            'returnable_quantity' => $delivered->contains($item->suborder_id) ? max(0, $item->quantity - (int) ($requested[$item->id] ?? 0)) : 0,
        ])->all()]]);
    }

    public function store(CreateCustomerCaseRequest $request, string $order, OpenCustomerCase $open): JsonResponse
    {
        $owned = $request->user()->orders()->findOrFail($order);
        $case = $open->open($owned, $request->validated());

        return (new CustomerCaseResource($case->load('messages')))->response()->setStatusCode($case->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $case): CustomerCaseResource
    {
        return new CustomerCaseResource($this->owned($request, $case)->load('messages'));
    }

    public function message(CustomerCaseMessageRequest $request, string $case): CustomerCaseResource
    {
        $owned = $this->owned($request, $case);
        DB::transaction(function () use ($owned, $request): void {
            $locked = CustomerCase::query()->whereKey($owned->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['resolved', 'rejected'], true)) {
                throw new DomainRuleViolation('case_closed', 'This request is closed. Open a new request for further help.', 409);
            }
            $locked->messages()->create(['author' => 'customer', 'body' => $request->string('message')->toString()]);
            $locked->touch();
        });

        return new CustomerCaseResource($owned->refresh()->load('messages'));
    }

    private function owned(Request $request, string $id): CustomerCase
    {
        return CustomerCase::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
