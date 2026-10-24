<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Orders\Actions\CancelOrder;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderDetailResource;
use App\Http\Support\OrderPresenter;
use Illuminate\Http\Request;

class OrderCancellationController extends Controller
{
    public function store(Request $request, string $order, CancelOrder $cancel, OrderPresenter $presenter, AuditLogger $audit): OrderDetailResource
    {
        $owned = $request->user()->orders()->findOrFail($order);
        $cancelled = $cancel->handle($owned);

        $audit->record('order.cancelled_by_customer', $request->user()->id, $cancelled, ['number' => $cancelled->number]);

        return new OrderDetailResource($presenter->load($cancelled));
    }
}
