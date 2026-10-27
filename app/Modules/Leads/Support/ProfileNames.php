<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Schema;

/**
 * The name on the person's Facebook or Instagram profile, beside the name the
 * lead goes by (user report, 27 Oct 2026). A lead form carries whatever the
 * person typed, while Messenger and Instagram search only profile names, so
 * the team needs the profile name to find the chat. `leads.profile_name` is
 * filled from Meta (LearnProfileName, SyncContactProfile) and arrives with
 * "Update system": until then nothing is shown or searched.
 */
final class ProfileNames
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasColumn('leads', 'profile_name');
    }

    /**
     * The profile name and where it is from, when it says something the lead's
     * own name does not.
     *
     * @return array{name: string, where: string, icon: string}|null
     */
    public static function shown(Lead $lead): ?array
    {
        $name = self::ready() ? trim((string) $lead->getAttribute('profile_name')) : '';

        if ($name === '' || self::same($name, (string) $lead->name)) {
            return null;
        }

        $instagram = $lead->source_type === LeadSource::Instagram;

        return ['name' => $name, 'where' => $instagram ? 'Instagram' : 'Facebook', 'icon' => $instagram ? 'instagram' : 'messenger'];
    }

    /** "amy wong" and "Amy  Wong" are the same name; "@amy.wong" is the same as "amy.wong". */
    private static function same(string $one, string $other): bool
    {
        $plain = fn (string $name): string => (string) preg_replace('/\s+/u', ' ', mb_strtolower(ltrim(trim($name), '@')));

        return $plain($one) === $plain($other);
    }
}
