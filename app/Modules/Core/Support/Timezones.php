<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Every time zone as a choice for the search picker (`<x-ui.picker>`): the
 * city, its country and how far it is from GMT today. A person looks for
 * "London", "Sydney", "Pakistan" or "UK", not for "Europe/London" in a list
 * of four hundred.
 */
final class Timezones
{
    /** Shown before anything is typed: where LiquifyCRM's brands usually work. */
    public const COMMON = ['Europe/London', 'Australia/Sydney', 'Australia/Melbourne', 'America/New_York', 'America/Los_Angeles', 'America/Toronto', 'Pacific/Auckland', 'Europe/Dublin'];

    /** @var list<array{value: string, label: string, hint: string, search: string}>|null */
    private static ?array $options = null;

    /** @return list<array{value: string, label: string, hint: string, search: string}> */
    public static function options(): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $now = new DateTimeImmutable('now');
        $options = [];

        foreach (DateTimeZone::listIdentifiers() as $id) {
            $zone = new DateTimeZone($id);
            $location = $zone->getLocation();
            $code = is_array($location) ? (string) $location['country_code'] : '';
            $country = Countries::name($code);
            $city = str_replace('_', ' ', (string) substr((string) strrchr('/'.$id, '/'), 1));
            $area = str_contains($id, '/') ? str_replace('_', ' ', (string) strstr($id, '/', true)) : '';

            $options[] = [
                'value' => $id,
                'label' => $city,
                'hint' => trim(($country ?? $area).' · '.self::offset($zone->getOffset($now)), ' ·'),
                'search' => mb_strtolower(implode(' ', array_filter([$city, $country, Countries::otherNames($code), $area, $id]))),
            ];
        }

        usort($options, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return self::$options = $options;
    }

    /** "GMT+1", "GMT-3:30", "GMT". */
    private static function offset(int $seconds): string
    {
        if ($seconds === 0) {
            return 'GMT';
        }

        $minutes = intdiv(abs($seconds), 60);

        return 'GMT'.($seconds > 0 ? '+' : '-').intdiv($minutes, 60).($minutes % 60 > 0 ? ':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '');
    }
}
