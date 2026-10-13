<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use DateTimeZone;
use Locale;
use Throwable;

/**
 * What a country says about a brand: the currency it sells in and the time
 * zone it works in. Used to fill both in from the brand's website, and to
 * find a time zone by its country's name.
 */
final class Countries
{
    /** Code => name, currency, main time zone, dialling code, other names people type. */
    private const KNOWN = [
        'GB' => ['United Kingdom', 'GBP', 'Europe/London', '44', 'uk england scotland wales britain'],
        'AU' => ['Australia', 'AUD', 'Australia/Sydney', '61', ''],
        'US' => ['United States', 'USD', 'America/New_York', '1', 'usa america'],
        'CA' => ['Canada', 'CAD', 'America/Toronto', '1', ''],
        'NZ' => ['New Zealand', 'NZD', 'Pacific/Auckland', '64', ''],
        'IE' => ['Ireland', 'EUR', 'Europe/Dublin', '353', ''],
        'DE' => ['Germany', 'EUR', 'Europe/Berlin', '49', ''],
        'FR' => ['France', 'EUR', 'Europe/Paris', '33', ''],
        'ES' => ['Spain', 'EUR', 'Europe/Madrid', '34', ''],
        'IT' => ['Italy', 'EUR', 'Europe/Rome', '39', ''],
        'NL' => ['Netherlands', 'EUR', 'Europe/Amsterdam', '31', 'holland'],
        'BE' => ['Belgium', 'EUR', 'Europe/Brussels', '32', ''],
        'PT' => ['Portugal', 'EUR', 'Europe/Lisbon', '351', ''],
        'AT' => ['Austria', 'EUR', 'Europe/Vienna', '43', ''],
        'AE' => ['United Arab Emirates', 'AED', 'Asia/Dubai', '971', 'uae dubai'],
        'SA' => ['Saudi Arabia', 'SAR', 'Asia/Riyadh', '966', 'ksa'],
        'PK' => ['Pakistan', 'PKR', 'Asia/Karachi', '92', ''],
        'IN' => ['India', 'INR', 'Asia/Kolkata', '91', ''],
        'SG' => ['Singapore', 'SGD', 'Asia/Singapore', '65', ''],
        'ZA' => ['South Africa', 'ZAR', 'Africa/Johannesburg', '27', ''],
    ];

    /** Address endings that look like a country but are sold to anybody. */
    private const OPEN_ENDINGS = ['co', 'io', 'ai', 'me', 'tv', 'cc', 'ws', 'fm', 'to', 'ly', 'gg', 'sh', 'so', 'is', 'im', 'la', 'vc', 'nu', 'tk'];

    /**
     * A state, province or city that is not in its country's main time zone.
     * Short codes are in capitals and are only matched in capitals ("WA 6000").
     */
    private const REGION_ZONES = [
        'AU' => [
            'Australia/Perth' => ['WA', 'western australia', 'perth'],
            'Australia/Brisbane' => ['QLD', 'queensland', 'brisbane', 'gold coast'],
            'Australia/Adelaide' => ['SA', 'south australia', 'adelaide'],
            'Australia/Darwin' => ['NT', 'northern territory', 'darwin'],
            'Australia/Hobart' => ['TAS', 'tasmania', 'hobart'],
            'Australia/Melbourne' => ['VIC', 'victoria', 'melbourne'],
        ],
        'US' => [
            'America/Los_Angeles' => ['CA', 'california', 'WA', 'washington', 'OR', 'oregon', 'NV', 'nevada'],
            'America/Denver' => ['CO', 'colorado', 'UT', 'utah', 'NM', 'new mexico', 'MT', 'montana', 'WY', 'wyoming', 'ID', 'idaho'],
            'America/Phoenix' => ['AZ', 'arizona'],
            'America/Chicago' => ['TX', 'texas', 'IL', 'illinois', 'MN', 'minnesota', 'WI', 'wisconsin', 'MO', 'missouri', 'LA', 'louisiana', 'OK', 'oklahoma', 'KS', 'kansas', 'IA', 'iowa', 'NE', 'nebraska', 'AR', 'arkansas', 'AL', 'alabama', 'MS', 'mississippi', 'TN', 'tennessee', 'ND', 'north dakota', 'SD', 'south dakota'],
        ],
        'CA' => [
            'America/Vancouver' => ['BC', 'british columbia', 'vancouver'],
            'America/Edmonton' => ['AB', 'alberta', 'calgary', 'edmonton'],
            'America/Winnipeg' => ['MB', 'manitoba', 'winnipeg'],
            'America/Regina' => ['SK', 'saskatchewan'],
            'America/Halifax' => ['NS', 'nova scotia', 'NB', 'new brunswick', 'halifax'],
        ],
    ];

    /**
     * A country as a website writes it ("GB", "UK", "United Kingdom",
     * "England") as its two-letter code, or null when it is not recognised.
     */
    public static function code(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        if ($value === 'uk') {
            return 'GB';
        }

        if (mb_strlen($value) === 2 && self::exists(mb_strtoupper($value))) {
            return mb_strtoupper($value);
        }

        foreach (self::KNOWN as $code => [$name, , , , $others]) {
            if ($value === mb_strtolower($name) || in_array($value, explode(' ', $others), true)) {
                return $code;
            }
        }

        return null;
    }

    public static function name(?string $code): ?string
    {
        if ($code === null || ! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        if (isset(self::KNOWN[$code])) {
            return self::KNOWN[$code][0];
        }

        // Every other country, where the server has the names (the intl extension).
        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('-'.$code, 'en');

            return $name !== '' && $name !== $code ? $name : null;
        }

        return null;
    }

    /** Other words people search a country by ("uk", "usa"). */
    public static function otherNames(?string $code): string
    {
        return self::KNOWN[(string) $code][4] ?? '';
    }

    /** The currency a country sells in, when LiquifyCRM records sales in it. */
    public static function currency(?string $code): ?string
    {
        $currency = self::KNOWN[(string) $code][1] ?? null;

        return in_array($currency, (array) config('liquify.currencies'), true) ? $currency : null;
    }

    /**
     * The time zone of a country, moved to the state or city named in the
     * address when the country has more than one ("Perth WA" is not Sydney).
     */
    public static function timezone(?string $code, string $address = ''): ?string
    {
        if ($code === null || ! self::exists($code)) {
            return null;
        }

        if ($address !== '' && isset(self::REGION_ZONES[$code])) {
            foreach (self::REGION_ZONES[$code] as $zone => $names) {
                foreach ($names as $name) {
                    if (preg_match('/\b'.preg_quote($name, '/').'\b/'.($name === mb_strtoupper($name) ? '' : 'i'), $address)) {
                        return $zone;
                    }
                }
            }
        }

        return self::KNOWN[$code][2] ?? (self::zones($code)[0] ?? null);
    }

    /** The country a phone number is dialled in ("+44 20 ..." is the United Kingdom). */
    public static function fromPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (! str_starts_with(trim((string) $phone), '+') && ! str_starts_with($digits, '00')) {
            return null;
        }

        $digits = ltrim($digits, '0');
        $best = null;

        foreach (self::KNOWN as $code => [, , , $dial]) {
            // +1 is shared by the United States and Canada: it says nothing by itself.
            if ($dial !== '1' && str_starts_with($digits, $dial) && ($best === null || strlen($dial) > strlen(self::KNOWN[$best][3]))) {
                $best = $code;
            }
        }

        return $best;
    }

    /** The country in a website's own address ("example.co.uk"), when its ending is a country's. */
    public static function fromHost(string $host): ?string
    {
        $ending = mb_strtolower((string) substr(strrchr('.'.$host, '.') ?: '', 1));

        if (strlen($ending) !== 2 || in_array($ending, self::OPEN_ENDINGS, true)) {
            return null;
        }

        return self::code($ending);
    }

    /** The country in a page's language tag ("en-GB", "en_AU"). "en-US" is every site's default, so it says nothing. */
    public static function fromLocale(?string $locale): ?string
    {
        if (! preg_match('/^[a-z]{2,3}[-_]([a-z]{2})$/i', trim((string) $locale), $match)) {
            return null;
        }

        $code = self::code($match[1]);

        return $code === 'US' ? null : $code;
    }

    private static function exists(string $code): bool
    {
        return isset(self::KNOWN[$code]) || self::zones($code) !== [];
    }

    /** @return list<string> */
    private static function zones(string $code): array
    {
        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return [];
        }

        try {
            return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code);
        } catch (Throwable) {
            return [];
        }
    }
}
