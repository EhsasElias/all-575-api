<?php

declare(strict_types=1);

namespace App\Domain\Addressing\Services;

use App\Domain\Addressing\Models\Address;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Identity\Models\User;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

final class AddressBook
{
    public const MAX_PER_USER = 10;

    /** @param  array<string, mixed>  $data  validated and normalised */
    public function add(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data): Address {
            if ($user->addresses()->count() >= self::MAX_PER_USER) {
                throw new DomainRuleViolation('address_limit_reached', 'You can save up to '.self::MAX_PER_USER.' addresses.');
            }

            $isFirst = ! $user->addresses()->exists();

            $address = $user->addresses()->create($data + [
                'service_zone_id' => $this->zoneIdFor($data),
                'is_default' => $isFirst,
            ]);

            if (($data['is_default'] ?? false) === true && ! $isFirst) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    /** @param  array<string, mixed>  $data  validated and normalised */
    public function update(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data): Address {
            $makeDefault = (bool) ($data['is_default'] ?? false);
            unset($data['is_default']);

            $address->update($data + ['service_zone_id' => $this->zoneIdFor($data)]);

            if ($makeDefault) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    public function makeDefault(Address $address): void
    {
        DB::transaction(function () use ($address): void {
            Address::query()->where('user_id', $address->user_id)->where('id', '!=', $address->id)->update(['is_default' => false]);
            $address->forceFill(['is_default' => true])->save();
        });
    }

    public function remove(Address $address): void
    {
        DB::transaction(function () use ($address): void {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                Address::query()->where('user_id', $address->user_id)->latest('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    private function zoneIdFor(array $data): ?int
    {
        return ServiceZone::resolve((float) $data['lat'], (float) $data['lng'])?->id;
    }
}
