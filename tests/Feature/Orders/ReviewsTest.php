<?php

namespace Tests\Feature\Orders;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\SuborderTransitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutScenario;
use Tests\TestCase;

class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function deliveredOrder(CheckoutScenario $s): Order
    {
        $order = $s->pay();
        foreach ([SuborderStatus::Preparing, SuborderStatus::Ready, SuborderStatus::OnTheWay, SuborderStatus::Delivered] as $stage) {
            app(SuborderTransitions::class)->move($order->suborders()->firstOrFail(), $stage, 'seller');
        }

        return $order;
    }

    public function test_a_product_cannot_be_reviewed_before_it_is_delivered(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $s->pay();

        $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $s->item['product']->slug, 'rating' => 5])
            ->assertUnprocessable()->assertJsonPath('code', 'review_not_allowed');
    }

    public function test_a_delivered_product_can_be_reviewed_once_and_updates_its_rating(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $this->deliveredOrder($s);
        $slug = $s->item['product']->slug;
        $s->user->update(['name' => 'Sara Alharbi']);

        $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $slug, 'rating' => 4, 'body' => 'Good value'])
            ->assertCreated()->assertJsonPath('data.rating', 4)->assertJsonPath('data.reviewer', 'Sara A.');

        $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $slug, 'rating' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'review_exists');

        $product = $s->item['product']->refresh();
        $this->assertSame(4.0, $product->rating_average);
        $this->assertSame(1, $product->rating_count);
        $this->getJson("/api/v1/products/{$slug}")->assertJsonPath('data.rating', ['average' => 4, 'count' => 1]);
    }

    public function test_only_products_in_that_order_can_be_reviewed_and_ratings_are_bounded(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $this->deliveredOrder($s);
        $other = $s->catalog->stockedProduct();

        $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $other['product']->slug, 'rating' => 5])
            ->assertUnprocessable()->assertJsonPath('code', 'review_not_allowed');
        $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $s->item['product']->slug, 'rating' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors('rating');
    }

    public function test_someone_elses_order_cannot_be_used_to_review(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $this->deliveredOrder($s);

        $this->actingAsCustomer(User::factory()->create());
        $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $s->item['product']->slug, 'rating' => 5])->assertNotFound();
    }

    public function test_a_review_can_be_edited_inside_its_window_only_by_its_author(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $order = $this->deliveredOrder($s);
        $id = $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $s->item['product']->slug, 'rating' => 2])->json('data.id');

        $this->patchJson("/api/v1/reviews/{$id}", ['rating' => 5, 'body' => 'Changed my mind'])
            ->assertOk()->assertJsonPath('data.rating', 5)->assertJsonPath('data.body', 'Changed my mind');
        $this->assertSame(5.0, $s->item['product']->refresh()->rating_average);

        $this->actingAsCustomer(User::factory()->create());
        $this->patchJson("/api/v1/reviews/{$id}", ['rating' => 1])->assertNotFound();

        $this->actingAsCustomer($s->user);
        $this->travel(8)->days();
        $this->patchJson("/api/v1/reviews/{$id}", ['rating' => 1])->assertStatus(409)->assertJsonPath('code', 'review_window_closed');
    }

    public function test_reviews_are_public_and_never_show_a_full_name_or_the_edit_window(): void
    {
        $s = (new CheckoutScenario($this))->withCart();
        $s->user->update(['name' => 'Sara Alharbi']);
        $order = $this->deliveredOrder($s);
        $slug = $s->item['product']->slug;
        $this->postJson("/api/v1/orders/{$order->id}/reviews", ['product_slug' => $slug, 'rating' => 5, 'body' => 'Great'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/products/{$slug}/reviews")->assertOk();

        $response->assertJsonPath('data.0.reviewer', 'Sara A.')->assertJsonPath('data.0.body', 'Great');
        $this->assertArrayNotHasKey('editable_until', $response->json('data.0'));
        $this->assertStringNotContainsString('Alharbi', $response->getContent());
    }
}
