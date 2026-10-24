<?php

declare(strict_types=1);

namespace App\Support;

/** Saudi mobile numbers only for now. Accepts 05XXXXXXXX, 5XXXXXXXX, 9665XXXXXXXX, +9665XXXXXXXX, 009665XXXXXXXX. */
final class SaudiPhone
{
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', self::toLatinDigits($input)) ?? '';

        if (preg_match('/^(?:\+|00)?966(5\d{8})$/', $digits, $matches) === 1) {
            return '+966'.$matches[1];
        }

        if (preg_match('/^0?(5\d{8})$/', $digits, $matches) === 1) {
            return '+966'.$matches[1];
        }

        return null;
    }

    private static function toLatinDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
