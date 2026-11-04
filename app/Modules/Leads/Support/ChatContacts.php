<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Brands\Models\Brand;

/**
 * The email address and phone number a person writes in a chat message
 * (user request, 4 Nov 2026: "capture the number and email from the chat and
 * show the merge"). A chat lead starts with a name only; once the person
 * writes their details they go on the lead, so the lead of the same person's
 * form is found by them. A number counts only when it is a real phone number
 * (an ISBN or an order number is not), and the brand's own contact details
 * are left out.
 */
final class ChatContacts
{
    /** Digits with the spaces, dots, dashes and brackets people put between them. */
    private const PHONE = '/(?<![\w+])\+?\(?\d[\d\s().\-]{6,22}\d(?!\w)/u';

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)*\.[A-Z]{2,}/i';

    /** @return array{email: ?string, phone: ?string} the first of each that the person wrote */
    public static function in(string $text, Brand $brand): array
    {
        return ['email' => self::emails($text, $brand)[0] ?? null, 'phone' => self::phones($text, $brand)[0] ?? null];
    }

    /** @return list<string> */
    public static function emails(string $text, ?Brand $brand = null): array
    {
        preg_match_all(self::EMAIL, $text, $matches);

        $own = $brand !== null ? self::ownEmails($brand) : [];
        $found = [];

        foreach ($matches[0] as $email) {
            $email = mb_strtolower(rtrim($email, '.'));
            $domain = substr((string) strrchr($email, '@'), 1);

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false && ! in_array($email, $own['emails'] ?? [], true) && ! in_array($domain, $own['domains'] ?? [], true)) {
                $found[] = $email;
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> in E.164 */
    public static function phones(string $text, ?Brand $brand = null): array
    {
        preg_match_all(self::PHONE, $text, $matches, PREG_OFFSET_CAPTURE);

        $own = $brand !== null ? PhoneNormalizer::strict($brand->company()['phone'], $brand) : null;
        $found = [];

        foreach ($matches[0] as [$candidate, $offset]) {
            // "ISBN 978-1-…" is a book, not a phone.
            if (preg_match('/isbn[\s:\-]*$/i', substr($text, max(0, $offset - 8), min(8, $offset)))) {
                continue;
            }

            $phone = self::phone($candidate, $brand);

            if ($phone !== null && $phone !== $own) {
                $found[] = $phone;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The number in one run of digits, also when the person wrote something
     * after it ("07700 900123 2pm"): the last group is dropped until it is a
     * real number or too short to be one.
     */
    private static function phone(string $candidate, ?Brand $brand): ?string
    {
        $candidate = trim($candidate);

        while (strlen((string) preg_replace('/\D/', '', $candidate)) >= 9) {
            if (strlen((string) preg_replace('/\D/', '', $candidate)) <= 15 && ($phone = PhoneNormalizer::strict($candidate, $brand)) !== null) {
                return $phone;
            }

            $shorter = preg_replace('/[\s().\-]+\d+\)?$/', '', $candidate);

            if ($shorter === null || $shorter === $candidate) {
                return null;
            }

            $candidate = trim($shorter);
        }

        return null;
    }

    /** @return array{emails: list<string>, domains: list<string>} */
    private static function ownEmails(Brand $brand): array
    {
        $email = mb_strtolower(trim($brand->company()['email']));
        $host = mb_strtolower((string) parse_url((string) $brand->website, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return ['emails' => $email !== '' ? [$email] : [], 'domains' => $host !== '' ? [$host] : []];
    }
}
