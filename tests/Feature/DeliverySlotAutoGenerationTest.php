<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Orders\Services\DeliverySlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeliverySlotAutoGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_checkout_request_generates_missing_windows_for_active_zone(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00', 'Asia/Riyadh'));
        $zone = ServiceZone::factory()->create(['is_active' => true]);
        $slots = app(DeliverySlots::class);

        $available = $slots->bookable($zone->id);
        $this->assertGreaterThan(0, $available->count());
        $this->assertTrue($available->every(fn ($slot) => $slot->starts_at->greaterThan(now()->addMinutes(60))));
        $this->assertTrue($available->every(fn ($slot) => $slot->service_zone_id === $zone->id));

        $count = $available->count();
        $this->assertCount($count, $slots->bookable($zone->id));
    }

    public function test_inactive_zone_does_not_generate_windows(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00', 'Asia/Riyadh'));
        $zone = ServiceZone::factory()->create(['is_active' => false]);

        $this->assertCount(0, app(DeliverySlots::class)->bookable($zone->id));
    }
}
