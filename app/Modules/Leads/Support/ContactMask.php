<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

/**
 * Masked contact details for Agents: +44 77•• •••123 and s•••@example.com.
 */
final class ContactMask
{
    public static function email(?string $email): ?string
    {
        if (blank($email) || ! str_contains((string) $email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', (string) $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    public static function phone(?string $phone): ?string
    {
        if (blank($phone)) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        $international = str_starts_with(trim((string) $phone), '+');

        if (mb_strlen($digits) < 7) {
            return str_repeat('•', mb_strlen($digits));
        }

        $prefixLength = $international ? 4 : 2;
        $prefix = mb_substr($digits, 0, $prefixLength);
        $suffix = mb_substr($digits, -3);
        $hidden = max(0, mb_strlen($digits) - $prefixLength - 3);
        $dots = str_repeat('•', $hidden);

        $head = $international
            ? '+'.mb_substr($prefix, 0, 2).' '.mb_substr($prefix, 2)
            : $prefix;

        return trim($head.mb_substr($dots, 0, 2).' '.mb_substr($dots, 2).$suffix);
    }
}
