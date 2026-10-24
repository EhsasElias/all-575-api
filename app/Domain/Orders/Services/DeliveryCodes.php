<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\DeliveryCode;
use App\Domain\Orders\Models\Suborder;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * The hand-over code. The customer reads it out (or shows it) and the courier types it in; a wrong guess
 * counts against a small limit so it cannot be brute-forced. It is unrelated to the login OTP.
 */
final class DeliveryCodes
{
    public function issue(Suborder $suborder): DeliveryCode
    {
        $length = (int) config('store.delivery_code.length');

        return DeliveryCode::query()->updateOrCreate(['suborder_id' => $suborder->id], [
            'code_encrypted' => str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT),
            'attempts' => 0,
            'max_attempts' => (int) config('store.delivery_code.max_attempts'),
            'expires_at' => now()->addHours((int) config('store.delivery_code.ttl_hours')),
            'verified_at' => null,
        ]);
    }

    /** What the customer sees: only while the code is live. */
    public function visibleTo(Suborder $suborder): ?DeliveryCode
    {
        $code = DeliveryCode::query()->where('suborder_id', $suborder->id)->first();

        return $code !== null && $code->verified_at === null && $code->expires_at->isFuture() ? $code : null;
    }

    /** Used by the courier flow. A match marks the code spent; a miss is counted; too many misses lock it. */
    public function verify(Suborder $suborder, string $given): bool
    {
        $failure = DB::transaction(function () use ($suborder, $given): ?DomainRuleViolation {
            $code = DeliveryCode::query()->lockForUpdate()->where('suborder_id', $suborder->id)->first();

            if ($code === null || $code->verified_at !== null || $code->expires_at->isPast()) {
                return new DomainRuleViolation('delivery_code_unavailable', 'There is no active hand-over code for this delivery.', 409);
            }

            if ($code->attempts >= $code->max_attempts) {
                return DomainRuleViolation::tooManyAttempts('delivery_code_locked', 'Too many wrong codes. Contact support.', 300);
            }

            if (hash_equals($code->code_encrypted, $given)) {
                $code->forceFill(['verified_at' => now()])->save();

                return null;
            }

            $code->increment('attempts');

            return new DomainRuleViolation('delivery_code_invalid', 'That code is not correct.', 422, [
                'attempts_remaining' => max(0, $code->max_attempts - $code->attempts),
            ]);
        });

        if ($failure !== null) {
            throw $failure;
        }

        return true;
    }
}
