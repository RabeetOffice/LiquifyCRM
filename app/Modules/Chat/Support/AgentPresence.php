<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use Illuminate\Support\Facades\Cache;

/**
 * Whether somebody of a brand's team is here to answer (user's plan, 3 Nov 2026). A person is "here" while a
 * page of the brand is open in their browser: every page already asks the server whether anything changed
 * every few seconds (`Live`), and that ask is noted here. Nothing runs in the background and nothing is
 * stored in the database; the note lives a minute in the cache. The website's chat box says "we are away"
 * and offers a form when nobody is.
 */
final class AgentPresence
{
    /** How long a note lasts: a browser that stops asking (tab closed, laptop shut) is away after this. */
    private const SECONDS = 60;

    /** The visitor's chat box asks often; the answer is worked out at most this often per brand. */
    private const ANSWER_SECONDS = 10;

    public static function seen(int $userId, int $brandId): void
    {
        $key = self::key($brandId, $userId);

        // Written once a minute, not on every ask.
        if (! Cache::has($key)) {
            Cache::put($key, true, self::SECONDS);
        }
    }

    public static function online(int $brandId): bool
    {
        return Cache::remember('chat:online:'.$brandId, self::ANSWER_SECONDS, function () use ($brandId): bool {
            $team = Membership::query()->acrossBrands()
                ->where('brand_id', $brandId)
                ->where('status', MembershipStatus::Active->value)
                ->whereIn('role', [BrandRole::Manager->value, BrandRole::Agent->value])
                ->pluck('user_id');

            $admins = User::query()->whereIn('role', [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value])->pluck('id');

            $keys = $team->merge($admins)->unique()->map(fn (mixed $id): string => self::key($brandId, (int) $id))->values()->all();

            return $keys !== [] && collect(Cache::many($keys))->filter()->isNotEmpty();
        });
    }

    /** Nobody is here after this (a test, or somebody switched the chat off). */
    public static function forget(int $brandId): void
    {
        Cache::forget('chat:online:'.$brandId);
    }

    private static function key(int $brandId, int $userId): string
    {
        return 'chat:present:'.$brandId.':'.$userId;
    }
}
