<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Brands\Models\Brand;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * Phone numbers are stored in E.164 so de-duplication by phone works across
 * sources. The brand's currency hints the home country for local numbers.
 */
final class PhoneNormalizer
{
    /** @var array<string, string> */
    private const COUNTRY_BY_CURRENCY = [
        'GBP' => 'GB', 'AUD' => 'AU', 'USD' => 'US', 'EUR' => 'IE', 'CAD' => 'CA', 'NZD' => 'NZ',
    ];

    public static function e164(?string $raw, ?Brand $brand = null): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $countries = array_values(array_unique(array_filter([
            self::COUNTRY_BY_CURRENCY[$brand->currency ?? ''] ?? null,
            'GB', 'AU', 'US', 'NZ', 'CA', 'IE',
        ])));

        try {
            $number = new PhoneNumber($raw, $countries);

            if ($number->isValid()) {
                return $number->formatE164();
            }

            // Numbers in reserved or newly opened ranges fail the strict check
            // but still have a clear country and length: format those too.
            $lenient = (new PhoneNumber($raw, $countries))->lenient();

            if ($lenient->isValid()) {
                return $lenient->formatE164();
            }
        } catch (Throwable) {
            // Keep what the person typed, minus spaces and punctuation.
        }

        return preg_replace('/[^\d+]/', '', $raw) ?: null;
    }
}
