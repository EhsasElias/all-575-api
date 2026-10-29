<?php

namespace Tests\Feature\Catalog;

use App\Domain\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogBuilder;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class VariablePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_prices_filter_sort_and_cursor_pagination_use_actual_product_prices(): void
    {
        $catalog = new CatalogBuilder;
        $cheap = $catalog->stockedProduct(product: ['price_halalas' => 100])['product'];
        $middle = $catalog->stockedProduct(product: ['price_halalas' => 250])['product'];
        $expensive = $catalog->stockedProduct()['product'];
        $this->getJson('/api/v1/products?min_price=100&max_price=250&sort=price_asc')->assertOk()
            ->assertJsonPath('data.0.id', $cheap->id)->assertJsonPath('data.1.id', $middle->id)->assertJsonCount(2, 'data');
        $first = $this->getJson('/api/v1/products?sort=price_desc&per_page=1')->assertOk()->assertJsonPath('data.0.id', $expensive->id);
        $this->getJson($first->json('links.next'))->assertOk()->assertJsonPath('data.0.id', $middle->id);
        $this->getJson('/api/v1/products?min_price=300&max_price=100')->assertUnprocessable();
        $this->getJson('/api/v1/products/'.$cheap->slug)->assertOk()->assertJsonPath('data.price.gross', 100)
            ->assertJsonPath('data.price.net', 87)->assertJsonPath('data.price.vat', 13)
            ->assertJsonPath('data.variants.0.price.gross', 100);
    }

    public function test_mixed_cart_quote_and_order_use_server_prices_and_preserve_order_snapshots(): void
    {
        $s = (new CheckoutScenario($this))->withCart(quantity: 2, product: ['price_halalas' => 100]);
        $this->putJson('/api/v1/cart/items/'.$s->item['variant']->id, ['quantity' => 2, 'unit_price' => 1])->assertOk()
            ->assertJsonPath('data.lines.0.unit_price', 100);
        $second = $s->addItem();
        $second['product']->update(['price_halalas' => 250]);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.subtotal', 450)->assertJsonPath('data.lines.0.unit_price', 100);
        $quote = $this->postJson('/api/v1/quotes', ['address_id' => $s->address->id])->assertCreated();
        $this->assertSame(450, $quote->json('data.subtotal'));
        $orderId = $s->checkout($quote->json('data.id'), method: 'cash_on_delivery')->assertCreated()->json('data.order.id');
        $order = Order::findOrFail($orderId);
        $this->assertSame(450, $order->subtotal_halalas);
        $this->assertSame(100, $order->items()->where('product_id', $s->item['product']->id)->first()->unit_price_halalas);
        $s->item['product']->update(['price_halalas' => 300]);
        $this->assertSame(450, $order->fresh()->subtotal_halalas);
    }

    public function test_price_change_requires_review_of_quote_and_command_rejects_out_of_range(): void
    {
        $s = (new CheckoutScenario($this))->withCart(product: ['price_halalas' => 100]);
        $quote = $s->quote();
        $this->artisan('catalog:set-price', ['slug' => $s->item['product']->slug, 'halalas' => '250'])->assertSuccessful();
        $s->checkout($quote)->assertConflict()->assertJsonPath('code', 'quote_changed');
        foreach (['99', '576', '1.50', '-100'] as $invalid) {
            $this->artisan('catalog:set-price', ['slug' => $s->item['product']->slug, 'halalas' => $invalid])->assertFailed();
        }
        $this->assertSame(250, $s->item['product']->fresh()->price_halalas);
    }

    public function test_best_sellers_count_completed_paid_units_only_and_paginate_without_duplicates(): void
    {
        $s = (new CheckoutScenario($this))->withCart(quantity: 3);
        $order = $s->pay();
        $order->update(['status' => 'completed']);
        $other = $s->catalog->stockedProduct()['product'];
        $unpaid = $order->replicate(['number', 'idempotency_key']);
        $unpaid->number = 'A575-TEST-UNPAID';
        $unpaid->idempotency_key = 'test-unpaid';
        $unpaid->paid_at = null;
        $unpaid->save();
        $item = $order->items()->first()->replicate();
        $item->order_id = $unpaid->id;
        $item->product_id = $other->id;
        $item->quantity = 100;
        $item->save();
        $first = $this->getJson('/api/v1/products?sort=best_selling&per_page=1')->assertOk()->assertJsonPath('data.0.id', $s->item['product']->id);
        $this->getJson($first->json('links.next'))->assertOk()->assertJsonPath('data.0.id', $other->id);
    }
}
