<?php

namespace Tests\Support;

use App\Domain\Addressing\Models\Address;
use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\DeliverySlot;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Models\Payment;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Everything a customer needs to reach the pay button: an address, a cart, a priced quote and a delivery slot. */
final class CheckoutScenario
{
    public CatalogBuilder $catalog;

    public User $user;

    public Address $address;

    public DeliverySlot $slot;

    /** @var array{product: Product, variant: ProductVariant, branch: Branch, stock: BranchStock} */
    public array $item;

    public function __construct(private readonly TestCase $test, ?CatalogBuilder $catalog = null, ?User $user = null, ?DeliverySlot $slot = null)
    {
        $this->catalog = $catalog ?? new CatalogBuilder;
        $this->user = $user ?? User::factory()->create();
        $this->address = Address::factory()->create(['user_id' => $this->user->id, 'service_zone_id' => $this->catalog->zone->id]);
        $this->slot = $slot ?? self::slotFor($this->catalog->zone);
    }

    public static function slotFor(ServiceZone $zone, int $capacity = 20, int $hoursAhead = 3): DeliverySlot
    {
        return DeliverySlot::query()->create([
            'service_zone_id' => $zone->id,
            'starts_at' => now()->addHours($hoursAhead),
            'ends_at' => now()->addHours($hoursAhead + 2),
            'capacity' => $capacity,
        ]);
    }

    /** @param  array<string, mixed>  $product */
    public function withCart(int $quantity = 1, int $onHand = 100, array $product = [], array $variant = []): self
    {
        $this->item = $this->catalog->stockedProduct($onHand, $product, $variant);
        $this->test->actingAsCustomer($this->user);
        $this->test->putJson('/api/v1/cart/items/'.$this->item['variant']->id, ['quantity' => $quantity])->assertOk();

        return $this;
    }

    public function quote(?string $promo = null): string
    {
        $this->test->actingAsCustomer($this->user);

        return $this->test->postJson('/api/v1/quotes', ['address_id' => $this->address->id, 'promo_code' => $promo])->assertCreated()->json('data.id');
    }

    public function checkout(?string $quoteId = null, string $key = 'idem-key-0001', string $method = 'mada', ?int $slotId = null): TestResponse
    {
        $this->test->actingAsCustomer($this->user);

        return $this->test->postJson('/api/v1/checkout', [
            'quote_id' => $quoteId ?? $this->quote(),
            'slot_id' => $slotId ?? $this->slot->id,
            'payment_method' => $method,
        ], ['Idempotency-Key' => $key]);
    }

    /** Plays the payment provider: a correctly signed callback delivered to our public webhook. */
    public function providerCallback(array $payload, ?string $signatureOverride = null): TestResponse
    {
        /** @var FakeGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $callback = $gateway->signedCallback($payload);

        return $this->test->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => $signatureOverride ?? $callback['headers'][FakeGateway::SIGNATURE_HEADER],
            'HTTP_ACCEPT' => 'application/json',
        ], $callback['body']);
    }

    /** Adds another product (optionally from another seller) to the same cart. */
    public function addItem(int $quantity = 1, ?Seller $seller = null): array
    {
        $item = $this->catalog->stockedProduct(100, seller: $seller);
        $this->test->actingAsCustomer($this->user);
        $this->test->putJson('/api/v1/cart/items/'.$item['variant']->id, ['quantity' => $quantity])->assertOk();

        return $item;
    }

    /** Checks out and plays the provider confirming the charge. Returns the confirmed order. */
    public function pay(?string $key = null): Order
    {
        $placed = $this->checkout(key: $key ?? 'idem-pay-'.uniqid())->assertCreated()->json('data');
        $payment = Payment::query()->findOrFail($placed['payment']['id']);
        $this->chargeSucceeded($payment->provider_reference, $payment->amount_halalas, 'evt_'.uniqid())->assertOk();

        return Order::query()->findOrFail($placed['order']['id']);
    }

    public function chargeSucceeded(string $reference, int $amount, string $eventId = 'evt_1'): TestResponse
    {
        return $this->providerCallback(['id' => $eventId, 'type' => 'charge.succeeded', 'reference' => $reference, 'amount' => $amount]);
    }
}
