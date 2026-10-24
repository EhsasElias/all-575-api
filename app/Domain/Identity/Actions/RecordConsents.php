<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\User;

final class RecordConsents
{
    /**
     * Appends consent rows. Terms and Privacy are recorded with the version the user saw;
     * marketing is recorded only when the caller states a choice.
     *
     * @param  array{terms_version?: string, privacy_version?: string, marketing?: bool}  $consents
     */
    public function handle(User $user, array $consents, string $locale, ?string $ip): void
    {
        $rows = [];

        $versioned = [
            [ConsentType::Terms, 'terms_version'],
            [ConsentType::Privacy, 'privacy_version'],
        ];

        foreach ($versioned as [$type, $key]) {
            if (isset($consents[$key])) {
                $rows[] = ['type' => $type, 'version' => $consents[$key], 'granted' => true];
            }
        }

        if (array_key_exists('marketing', $consents)) {
            $rows[] = ['type' => ConsentType::Marketing, 'version' => null, 'granted' => (bool) $consents['marketing']];
        }

        foreach ($rows as $row) {
            $user->consents()->create($row + ['locale' => $locale, 'ip_address' => $ip]);
        }
    }
}
