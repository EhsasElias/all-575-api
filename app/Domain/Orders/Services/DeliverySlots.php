<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Orders\Models\DeliverySlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DeliverySlots
{
    /** @return Collection<int, DeliverySlot> slots a customer can still book, soonest first */
    public function bookable(int $zoneId): Collection
    {
        $earliest = now()->addMinutes((int) config('store.slots.lead_minutes'));

        return DeliverySlot::query()
            ->where('service_zone_id', $zoneId)
            ->where('starts_at', '>', $earliest)
            ->where('starts_at', '<=', now()->addDays((int) config('store.slots.days_ahead') + 1))
            ->orderBy('starts_at')
            ->get();
    }

    /** Creates any missing windows for every active zone and returns how many were added. Safe to run repeatedly. */
    public function generate(): int
    {
        $created = 0;
        $timezone = (string) config('store.slots.timezone');
        $length = (int) config('store.slots.length_minutes');

        foreach (ServiceZone::query()->active()->get() as $zone) {
            for ($day = 0; $day <= (int) config('store.slots.days_ahead'); $day++) {
                $start = Carbon::now($timezone)->addDays($day)->setTime((int) config('store.slots.first_hour'), 0);
                $dayEnd = $start->copy()->setTime((int) config('store.slots.last_hour'), 0);

                while ($start->copy()->addMinutes($length)->lte($dayEnd)) {
                    $slot = DeliverySlot::query()->firstOrCreate(
                        ['service_zone_id' => $zone->id, 'starts_at' => $start->copy()->utc()],
                        ['ends_at' => $start->copy()->addMinutes($length)->utc(), 'capacity' => (int) config('store.slots.capacity')],
                    );
                    $created += $slot->wasRecentlyCreated ? 1 : 0;
                    $start->addMinutes($length);
                }
            }
        }

        return $created;
    }
}
