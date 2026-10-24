<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\ServiceZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogBuilder;
use Tests\TestCase;

class ProductBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private CatalogBuilder $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new CatalogBuilder;
    }

    /** @return list<string> */
    private function slugs(string $query = ''): array
    {
        return collect($this->getJson('/api/v1/products'.$query)->assertOk()->json('data'))->pluck('slug')->all();
    }

    public function test_guests_can_browse_published_products(): void
    {
        $this->catalog->stockedProduct(product: ['slug' => 'biscuits']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'biscuits')
            ->assertJsonPath('data.0.price', ['gross' => 575, 'net' => 500, 'vat' => 75, 'currency' => 'SAR']);
    }

    public function test_unpublished_products_and_hidden_sellers_and_categories_never_appear(): void
    {
        $this->catalog->stockedProduct(product: ['slug' => 'visible']);
        $this->catalog->stockedProduct(product: ['slug' => 'draft', 'status' => 'draft']);
        $this->catalog->stockedProduct(product: ['slug' => 'suspended-seller'], seller: Seller::factory()->suspended()->create());
        $this->catalog->stockedProduct(product: ['slug' => 'hidden-category'], category: Category::factory()->create(['is_active' => false]));

        $this->assertSame(['visible'], $this->slugs());
        $this->getJson('/api/v1/products/draft')->assertNotFound();
        $this->getJson('/api/v1/products/suspended-seller')->assertNotFound();
    }

    public function test_filtering_by_a_parent_category_includes_its_children(): void
    {
        $parent = Category::factory()->create(['slug' => 'home']);
        $child = Category::factory()->create(['slug' => 'kitchen', 'parent_id' => $parent->id]);
        $other = Category::factory()->create(['slug' => 'toys']);

        $this->catalog->stockedProduct(product: ['slug' => 'mug'], category: $child);
        $this->catalog->stockedProduct(product: ['slug' => 'hanger'], category: $parent);
        $this->catalog->stockedProduct(product: ['slug' => 'ball'], category: $other);

        $this->assertEqualsCanonicalizing(['mug', 'hanger'], $this->slugs('?category=home'));
        $this->assertSame(['mug'], $this->slugs('?category=kitchen'));
        $this->assertSame([], $this->slugs('?category=unknown'));
    }

    public function test_search_matches_either_language_and_treats_wildcards_literally(): void
    {
        $this->catalog->stockedProduct(product: ['slug' => 'a', 'name_en' => 'Tea bags', 'name_ar' => 'شاي']);
        $this->catalog->stockedProduct(product: ['slug' => 'b', 'name_en' => 'Pens', 'name_ar' => 'أقلام']);
        $this->catalog->stockedProduct(product: ['slug' => 'c', 'name_en' => 'Pen_set', 'name_ar' => 'طقم']);

        $this->assertSame(['a'], $this->slugs('?q=tea'));
        $this->assertSame(['b'], $this->slugs('?q='.urlencode('أقلام')));
        $this->assertSame(['c'], $this->slugs('?q=en_'));
        $this->assertSame([], $this->slugs('?q=%25%25'));
        $this->getJson('/api/v1/products?q=a')->assertUnprocessable();
    }

    public function test_every_product_costs_the_platform_price_whatever_its_data_says(): void
    {
        $this->catalog->stockedProduct(product: ['slug' => 'tea']);
        $this->catalog->stockedProduct(product: ['slug' => 'scarf']);

        $prices = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('price.gross')->unique()->all();
        $this->assertSame([575], $prices);
    }

    public function test_zero_rated_goods_cost_the_same_5_75_with_no_vat_in_it(): void
    {
        $this->catalog->stockedProduct(product: ['slug' => 'staple', 'vat_class' => 'zero']);

        $this->getJson('/api/v1/products/staple')
            ->assertJsonPath('data.price', ['gross' => 575, 'net' => 575, 'vat' => 0, 'currency' => 'SAR'])
            ->assertJsonPath('data.variants.0.price.vat', 0);
    }

    public function test_the_price_is_one_setting_and_changing_it_changes_every_product(): void
    {
        $this->catalog->stockedProduct(product: ['slug' => 'tea']);
        config(['store.price_halalas' => 600]);

        $this->getJson('/api/v1/products/tea')->assertJsonPath('data.price.gross', 600)->assertJsonPath('data.variants.0.price.net', 522);
    }

    public function test_results_are_paginated_with_a_cursor(): void
    {
        foreach (range(1, 5) as $i) {
            $this->catalog->stockedProduct(product: ['slug' => "p{$i}"]);
        }

        $first = $this->getJson('/api/v1/products?per_page=2')->assertOk();
        $this->assertCount(2, $first->json('data'));
        $next = $first->json('links.next');
        $this->assertNotNull($next);

        $second = $this->getJson($next)->assertOk();
        $this->assertCount(2, $second->json('data'));
        $this->assertEmpty(array_intersect(
            collect($first->json('data'))->pluck('slug')->all(),
            collect($second->json('data'))->pluck('slug')->all(),
        ));
    }

    public function test_availability_is_unknown_until_a_zone_is_chosen_then_reflects_unreserved_stock(): void
    {
        $this->catalog->stockedProduct(onHand: 50, product: ['slug' => 'plenty']);
        $low = $this->catalog->stockedProduct(onHand: 5, product: ['slug' => 'few']);
        $gone = $this->catalog->stockedProduct(onHand: 3, product: ['slug' => 'held']);
        $gone['stock']->update(['reserved' => 3]);

        $this->assertSame('unknown', $this->getJson('/api/v1/products')->json('data.0.availability'));

        $byslug = collect($this->getJson('/api/v1/products?zone_id='.$this->catalog->zone->id)->json('data'))->pluck('availability', 'slug');
        $this->assertSame('in_stock', $byslug['plenty']);
        $this->assertSame('low_stock', $byslug['few']);
        $this->assertSame('out_of_stock', $byslug['held']);
        $this->assertSame(5, $low['stock']->refresh()->available());
    }

    public function test_stock_in_another_zone_does_not_count(): void
    {
        $this->catalog->stockedProduct(onHand: 50, product: ['slug' => 'riyadh-only']);
        $otherZone = ServiceZone::factory()->create(['name_en' => 'Jeddah']);

        $this->assertSame('out_of_stock', $this->getJson('/api/v1/products?zone_id='.$otherZone->id)->json('data.0.availability'));
    }

    public function test_the_product_page_lists_variants_with_net_vat_and_gross_prices(): void
    {
        $built = $this->catalog->stockedProduct(onHand: 20, product: ['slug' => 'socks'], variant: ['name_en' => 'M', 'name_ar' => 'م']);
        ProductVariant::factory()->create(['product_id' => $built['product']->id, 'sku' => 'SOCKS-L', 'name_en' => 'L', 'name_ar' => 'ل', 'sort_order' => 1]);

        $response = $this->getJson('/api/v1/products/socks?zone_id='.$this->catalog->zone->id)->assertOk();

        $response->assertJsonPath('data.variants.0.price', ['gross' => 575, 'net' => 500, 'vat' => 75, 'currency' => 'SAR'])
            ->assertJsonPath('data.variants.0.availability', 'in_stock')
            ->assertJsonPath('data.variants.1.availability', 'out_of_stock')
            ->assertJsonCount(2, 'data.variants');
    }

    public function test_zone_resolution_says_whether_we_deliver_to_a_point(): void
    {
        $zone = $this->catalog->zone;

        $this->getJson('/api/v1/zones/resolve?lat=24.7500&lng=46.7000')
            ->assertOk()->assertJsonPath('data.serviceable', true)->assertJsonPath('data.zone.id', $zone->id);

        $this->getJson('/api/v1/zones/resolve?lat=21.5433&lng=39.1728')
            ->assertOk()->assertJsonPath('data.serviceable', false)->assertJsonPath('data.zone', null);

        $this->getJson('/api/v1/zones/resolve?lat=abc&lng=1')->assertUnprocessable();
    }

    public function test_categories_are_listed_as_a_tree_without_inactive_ones(): void
    {
        $parent = Category::factory()->create(['slug' => 'home', 'sort_order' => 1]);
        Category::factory()->create(['slug' => 'kitchen', 'parent_id' => $parent->id]);
        Category::factory()->create(['slug' => 'old', 'parent_id' => $parent->id, 'is_active' => false]);
        Category::factory()->create(['slug' => 'retired', 'is_active' => false]);

        $response = $this->getJson('/api/v1/categories')->assertOk();

        $this->assertSame(['home'], collect($response->json('data'))->pluck('slug')->all());
        $this->assertSame(['kitchen'], collect($response->json('data.0.children'))->pluck('slug')->all());
    }
}
