<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * Times as the person looking at them lives them. Everything is stored in
 * UTC; a person sees and types times in their own time zone: the one they
 * chose in their Profile, else their computer's (the browser sends it in the
 * `lq_tz` cookie, see app.js), else LiquifyCRM's default.
 */
final class ViewerTime
{
    public const COOKIE = 'lq_tz';

    /** @var array<string, int>|null */
    private static ?array $known = null;

    public static function zone(?User $user = null): string
    {
        $viewer = auth()->user();
        $user ??= $viewer instanceof User ? $viewer : null;
        // The computer's zone is the viewer's own: it says nothing about somebody else.
        $computer = $user === null || $user->is($viewer) ? self::computerZone() : null;

        foreach ([$user?->timezone, $computer] as $zone) {
            if (self::valid($zone)) {
                return $zone;
            }
        }

        return (string) config('liquify.default_timezone');
    }

    /** The time zone of the computer this request came from, when the browser has said. */
    public static function computerZone(): ?string
    {
        $zone = request()->cookie(self::COOKIE);

        return self::valid($zone) ? $zone : null;
    }

    /** @phpstan-assert-if-true non-empty-string $zone */
    public static function valid(mixed $zone): bool
    {
        self::$known ??= array_flip(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));

        return is_string($zone) && $zone !== '' && isset(self::$known[$zone]);
    }

    /** A stored time in the viewer's zone. */
    public static function local(CarbonInterface $at, ?User $user = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::zone($user));
    }

    public static function now(?User $user = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone($user));
    }

    /**
     * A date and time the viewer typed (a `datetime-local` or `date` field),
     * read in their zone and turned into the stored (UTC) moment. Null when it
     * is not a date.
     */
    public static function parse(?string $typed, ?User $user = null): ?CarbonImmutable
    {
        if ($typed === null || trim($typed) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($typed, self::zone($user))->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /** What a `datetime-local` field shows for a stored moment. */
    public static function field(?CarbonInterface $at, ?User $user = null): string
    {
        return $at === null ? '' : self::local($at, $user)->format('Y-m-d\TH:i');
    }

    /** The start of the viewer's today, as a stored (UTC) moment, for queries. */
    public static function startOfToday(?User $user = null): CarbonImmutable
    {
        return self::now($user)->startOfDay()->setTimezone((string) config('app.timezone'));
    }

    /** The end of the viewer's today, as a stored (UTC) moment, for queries. */
    public static function endOfToday(?User $user = null): CarbonImmutable
    {
        return self::now($user)->endOfDay()->setTimezone((string) config('app.timezone'));
    }

    /** "London", "Sydney", "New York": the city of a zone, for people. */
    public static function city(string $zone): string
    {
        return str_replace('_', ' ', (string) substr((string) strrchr('/'.$zone, '/'), 1));
    }
}
