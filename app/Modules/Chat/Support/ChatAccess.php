<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Membership;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\MembershipStatus;
use Illuminate\Support\Collection;

/**
 * Who sees the live chats of visitors who have not given their details (and so have no lead yet). Admins
 * see them everywhere and a brand's Managers in their brand; an Agent sees them unless the brand's widget is
 * set so only Managers do. Once a chat has a lead, the lead decides who sees it, as for every chat.
 */
final class ChatAccess
{
    public static function canSeeVisitors(User $user, int $brandId): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        $membership = $user->membershipFor($brandId);

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            return false;
        }

        return match ($membership->role) {
            BrandRole::Manager => true,
            BrandRole::Agent => ! ChatWidget::query()->forBrand($brandId)->where('agents_see_visitors', false)->exists(),
            default => false,
        };
    }

    /**
     * The people of a brand a website chat can be given to: its Managers and Agents who see visitors.
     *
     * @return Collection<int, User>
     */
    public static function team(int $brandId): Collection
    {
        $ids = Membership::query()->acrossBrands()
            ->where('brand_id', $brandId)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->isActive() && self::canSeeVisitors($user, $brandId))
            ->values();
    }

    /**
     * The brands whose anonymous chats this person sees (an Admin sees every brand's; inside a brand the
     * current brand's scope narrows it to that one).
     *
     * @return list<int>
     */
    public static function visitorBrandIds(User $user): array
    {
        return $user->memberships()->acrossBrands()
            ->where('status', MembershipStatus::Active->value)
            ->pluck('brand_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => self::canSeeVisitors($user, $id))
            ->values()
            ->all();
    }
}
