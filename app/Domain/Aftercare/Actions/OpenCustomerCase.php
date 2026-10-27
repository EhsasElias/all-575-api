<?php

namespace App\Domain\Aftercare\Actions;

use App\Domain\Aftercare\Models\CustomerCase;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Order;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class OpenCustomerCase
{
    /** @param array<string, mixed> $input */
    public function open(Order $order, array $input): CustomerCase
    {
        $hash = hash('sha256', json_encode([
            $order->id, $input['kind'], $input['subject'], $input['message'],
            $input['order_item_id'] ?? null, $input['quantity'] ?? null,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($order, $input, $hash): CustomerCase {
                // Serialize requests for this order, including competing partial returns.
                Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $existing = CustomerCase::query()->where('user_id', $order->user_id)->where('request_key', $input['request_key'])->first();
                if ($existing !== null) {
                    return $this->replay($existing, $hash);
                }
                if ($input['kind'] === 'return') {
                    $item = $order->items()->findOrFail($input['order_item_id']);
                    $delivered = $order->suborders()->whereKey($item->suborder_id)->where('status', SuborderStatus::Delivered->value)->exists();
                    if (! $delivered) {
                        throw new DomainRuleViolation('return_not_delivered', 'Returns can be requested after delivery.', 409);
                    }
                    $requested = CustomerCase::query()->where('order_item_id', $item->id)->where('status', '!=', 'rejected')->sum('quantity');
                    if ($input['quantity'] > $item->quantity - $requested) {
                        throw new DomainRuleViolation('return_quantity_exceeded', 'This quantity already has a return request.', 409);
                    }
                }
                $case = CustomerCase::query()->create([
                    'user_id' => $order->user_id, 'order_id' => $order->id,
                    'kind' => $input['kind'], 'status' => 'submitted', 'subject' => $input['subject'],
                    'order_item_id' => $input['order_item_id'] ?? null, 'quantity' => $input['quantity'] ?? null,
                    'request_key' => $input['request_key'], 'request_hash' => $hash,
                ]);
                $case->messages()->create(['author' => 'customer', 'body' => $input['message']]);

                return $case;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = CustomerCase::query()->where('user_id', $order->user_id)->where('request_key', $input['request_key'])->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $hash);
        }
    }

    private function replay(CustomerCase $case, string $hash): CustomerCase
    {
        if (! hash_equals($case->request_hash, $hash)) {
            throw new DomainRuleViolation('case_key_reused', 'Use a new request key for a different request.', 409);
        }

        return $case;
    }
}
