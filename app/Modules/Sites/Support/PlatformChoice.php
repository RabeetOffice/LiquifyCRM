<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Core\Support\KnownSchema;
use App\Modules\Sites\Enums\SitePlatform;
use App\Modules\Sites\Models\Site;
use Carbon\CarbonInterface;

/**
 * What kind of website a site is, when a person chose it (user request,
 * 3 Nov 2026): custom PHP, WordPress, or WordPress with an Elementor form.
 * `sites.platform_chosen_at` arrives with "Update system"; until then the
 * choice is still saved, it is only guessed again from the homepage.
 */
final class PlatformChoice
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || KnownSchema::column('sites', 'platform_chosen_at');
    }

    public static function isChosen(Site $site): bool
    {
        return self::ready() && $site->getAttribute('platform_chosen_at') !== null;
    }

    /** @return array{platform: SitePlatform, platform_chosen_at?: CarbonInterface} the columns that record a person's choice */
    public static function attributes(SitePlatform $platform): array
    {
        return self::ready() ? ['platform' => $platform, 'platform_chosen_at' => now()] : ['platform' => $platform];
    }
}
