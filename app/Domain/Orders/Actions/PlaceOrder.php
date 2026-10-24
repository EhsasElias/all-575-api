<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Cart\Models\PromoCode;
use App\Domain\Cart\Models\PromoRedemption;
use App\Domain\Cart\Models\QuoteRecord;
use App\Domain\Cart\Services\PromoCodes;
use App\Domain\Cart\Services\QuoteBuilder;
use App\Domain\Cart\Services\QuoteData;
use App\Domain\Cart\Services\QuoteLine;
use App\Domain\Cart\Services\QuoteService;
use App\Domain\Inventory\Services\StockReservations;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\DeliverySlot;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderConfirmation;
use App\Domain\Orders\Services\UnpaidOrderCancellation;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateways\ChargeSession;
use App\Domain\Payments\Models\Payment;
use App\Support\Exceptions\DomainRuleViolation;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an agreed quote into an unpaid order that holds stock and a delivery slot, then asks the payment provider
 * for a charge. Nothing is confirmed here: only a verified provider callback (see PaymentProcessor) does that.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly QuoteBuilder $builder,
        private readonly QuoteService $quotes,
        private readonly PromoCodes $promos,
        private readonly StockReservations $stock,
        private readonly OrderConfirmation $confirmation,
        private readonly UnpaidOrderCancellation $cancellation,
        private readonly PaymentGateway $gateway,
    ) {}

    public function handle(PlaceOrderCommand $command): PlacedOrder
    {
        if ($existing = $this->findByIdempotencyKey($command)) {
            return $this->replay($existing, $command);
        }

        $fresh = $this->freshQuoteMatchingWhatTheCustomerSaw($command);

        try {
            [$order, $payment] = DB::transaction(fn (): array => $this->createOrder($command, $fresh));
        } catch (UniqueConstraintViolationException) {
            // A parallel request with the same key won the race: answer with its order.
            return $this->replay($this->findByIdempotencyKey($command) ?? throw new DomainRuleViolation('checkout_conflict', 'Please try again.', 409), $command);
        }

        return new PlacedOrder($order, $payment, $payment === null ? null : $this->openCharge($order, $payment), false);
    }

    private function findByIdempotencyKey(PlaceOrderCommand $command): ?Order
    {
        return Order::query()->where('user_id', $command->user->id)->where('idempotency_key', $command->idempotencyKey)->first();
    }

    private function replay(Order $order, PlaceOrderCommand $command): PlacedOrder
    {
        if ($order->quote_id !== $command->quoteId) {
            throw new DomainRuleViolation('idempotency_key_reused', 'This key was already used for a different order.', 422);
        }

        return new PlacedOrder($order, $order->payments()->latest('created_at')->first(), null, true);
    }

    private function freshQuoteMatchingWhatTheCustomerSaw(PlaceOrderCommand $command): QuoteData
    {
        $record = QuoteRecord::query()->where('user_id', $command->user->id)->find($command->quoteId)
            ?? throw new DomainRuleViolation('quote_not_found', 'Review your order again.', 404);

        if ($record->isExpired()) {
            throw new DomainRuleViolation('quote_expired', 'Your prices expired. Review your order again.', 422);
        }

        $address = $command->user->addresses()->find($record->address_id)
            ?? throw new DomainRuleViolation('address_missing', 'The delivery address no longer exists.', 422);

        $fresh = $this->builder->build($command->user, $address, $record->promo_code);

        if (! $fresh->canCheckout()) {
            throw new DomainRuleViolation('cart_has_unavailable_items', 'Some items are no longer available.', 409, ['issues' => $fresh->issues]);
        }

        if ($fresh->fingerprint() !== $record->fingerprint) {
            $updated = $this->quotes->create($command->user, $address, $record->promo_code);

            throw new DomainRuleViolation('quote_changed', 'Prices or availability changed. Please review your order.', 409, [
                'quote' => ['id' => $updated->id, 'expires_at' => $updated->expires_at] + $updated->payload,
            ]);
        }

        return $fresh;
    }

    /** @return array{0: Order, 1: ?Payment} */
    private function createOrder(PlaceOrderCommand $command, QuoteData $quote): array
    {
        $address = $command->user->addresses()->findOrFail($quote->addressId);
        $slot = $this->bookSlot($command->slotId, $quote->zoneId);
        $expiresAt = now()->addMinutes((int) config('store.checkout.reservation_minutes'));

        $order = Order::query()->create([
            'number' => $this->newOrderNumber(),
            'user_id' => $command->user->id,
            'status' => OrderStatus::PendingPayment,
            'idempotency_key' => $command->idempotencyKey,
            'quote_id' => $command->quoteId,
            'recipient_name' => $address->recipient_name, 'recipient_phone' => $address->recipient_phone,
            'region' => $address->region, 'city' => $address->city, 'district' => $address->district, 'street' => $address->street,
            'building_number' => $address->building_number, 'additional_number' => $address->additional_number,
            'postal_code' => $address->postal_code, 'short_address' => $address->short_address,
            'unit' => $address->unit, 'floor' => $address->floor, 'delivery_notes' => $address->delivery_notes,
            'lat' => $address->lat, 'lng' => $address->lng, 'service_zone_id' => $quote->zoneId,
            'delivery_slot_id' => $slot->id, 'slot_starts_at' => $slot->starts_at, 'slot_ends_at' => $slot->ends_at,
            'currency' => config('store.currency'),
            'subtotal_halalas' => $quote->subtotal->halalas, 'discount_halalas' => $quote->discount->halalas,
            'delivery_fee_halalas' => $quote->deliveryFee->halalas, 'total_halalas' => $quote->total->halalas,
            'vat_halalas' => $quote->totalVat()->halalas, 'vat_breakdown' => $quote->vat,
            'promo_code' => $quote->promoCode, 'customer_notes' => $command->notes,
            'placed_at' => now(),
        ]);

        $quantities = [];
        foreach ($quote->lines as $line) {
            $quantities[$line->variantId] = $line->quantity;
        }
        $branches = $this->stock->hold($order, $quantities, $quote->zoneId, $expiresAt);

        $this->createSuborders($order, $quote, $branches);
        $this->redeemPromo($command, $order, $quote);
        $order->record('order_placed', 'customer');

        return [$order, $this->createPayment($order, $command->method, $expiresAt)];
    }

    private function bookSlot(int $slotId, int $zoneId): DeliverySlot
    {
        $slot = DeliverySlot::query()->lockForUpdate()->find($slotId);

        if ($slot === null || $slot->service_zone_id !== $zoneId) {
            throw new DomainRuleViolation('slot_invalid', 'Choose a delivery time again.', 422);
        }

        if ($slot->starts_at->lte(now()->addMinutes((int) config('store.slots.lead_minutes')))) {
            throw new DomainRuleViolation('slot_too_soon', 'That delivery time is no longer available.', 409);
        }

        $booked = DeliverySlot::query()->whereKey($slot->id)->whereColumn('booked', '<', 'capacity')->increment('booked');

        if ($booked !== 1) {
            throw new DomainRuleViolation('slot_full', 'That delivery time just filled up.', 409);
        }

        return $slot;
    }

    /** @param  array<int, int>  $branches  variant id => branch id */
    private function createSuborders(Order $order, QuoteData $quote, array $branches): void
    {
        $groups = [];
        foreach ($quote->lines as $line) {
            $groups[$branches[$line->variantId]][] = $line;
        }

        foreach ($groups as $branchId => $lines) {
            /** @var list<QuoteLine> $lines */
            $suborder = $order->suborders()->create([
                'seller_id' => $lines[0]->sellerId,
                'branch_id' => $branchId,
                'status' => SuborderStatus::AwaitingPayment,
                'subtotal_halalas' => array_sum(array_map(fn (QuoteLine $line): int => $line->lineGross->halalas, $lines)),
                'discount_halalas' => array_sum(array_map(fn (QuoteLine $line): int => $line->discount->halalas, $lines)),
            ]);

            foreach ($lines as $line) {
                $suborder->items()->create([
                    'order_id' => $order->id,
                    'variant_id' => $line->variantId, 'product_id' => $line->productId, 'product_slug' => $line->productSlug,
                    'name_ar' => (string) $line->name['ar'], 'name_en' => (string) $line->name['en'],
                    'variant_name_ar' => $line->variantName['ar'] ?? null, 'variant_name_en' => $line->variantName['en'] ?? null,
                    'image_path' => $line->imageUrl === null ? null : parse_url($line->imageUrl, PHP_URL_PATH),
                    'quantity' => $line->quantity,
                    'unit_price_halalas' => $line->unitGross->halalas, 'line_price_halalas' => $line->lineGross->halalas,
                    'discount_halalas' => $line->discount->halalas, 'line_total_halalas' => $line->lineTotal()->halalas,
                    'vat_class' => $line->vatClass->value, 'vat_rate_basis_points' => $line->vatRateBasisPoints,
                ]);
            }
        }
    }

    private function redeemPromo(PlaceOrderCommand $command, Order $order, QuoteData $quote): void
    {
        if ($quote->promoCode === null) {
            return;
        }

        $promo = PromoCode::query()->lockForUpdate()->where('code', $quote->promoCode)->firstOrFail();
        // Checked again under the lock: the code may have been used up since the quote was priced.
        $this->promos->find($promo->code, $command->user, $quote->subtotal);

        PromoRedemption::query()->create([
            'promo_code_id' => $promo->id, 'user_id' => $command->user->id, 'order_id' => $order->id,
            'discount_halalas' => $quote->discount->halalas,
        ]);
    }

    private function createPayment(Order $order, PaymentMethod $method, CarbonInterface $expiresAt): ?Payment
    {
        if ($order->total_halalas === 0) {
            $free = $order->payments()->create([
                'provider' => 'none', 'method' => PaymentMethod::None, 'status' => PaymentStatus::Pending,
                'amount_halalas' => 0, 'currency' => $order->currency, 'expires_at' => $expiresAt,
            ]);
            $this->confirmation->confirm($order, $free);

            return null;
        }

        return $order->payments()->create([
            'provider' => $this->gateway->name(), 'method' => $method, 'status' => PaymentStatus::Pending,
            'amount_halalas' => $order->total_halalas, 'currency' => $order->currency, 'expires_at' => $expiresAt,
        ]);
    }

    private function openCharge(Order $order, Payment $payment): ChargeSession
    {
        try {
            $session = $this->gateway->createCharge($payment);
        } catch (Throwable $e) {
            // No charge exists, so nothing can ever confirm this order: give the stock and slot back now.
            $this->cancellation->cancel($order, 'payment_provider_error');

            throw $e instanceof DomainRuleViolation ? $e : new DomainRuleViolation('payment_provider_error', 'The payment provider is not responding. Please try again.', 502);
        }

        $payment->forceFill(['provider_reference' => $session->providerReference])->save();

        return $session;
    }

    private function newOrderNumber(): string
    {
        do {
            $number = config('store.checkout.order_number_prefix').'-'.Str::upper(Str::random(8));
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
