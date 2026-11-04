<?php

declare(strict_types=1);

namespace App\Modules\Chat\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\CannedReply;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Core\Support\CurrentBrand;

/**
 * Everybody in a brand uses the saved replies the company, the brand and they themselves wrote. The company's are
 * written by Admins, a brand's by Admins and its Managers, a person's own by that person.
 */
final class CannedReplyPolicy
{
    public function viewAny(User $user): bool
    {
        $brand = app(CurrentBrand::class)->get();

        return LiveChat::extrasReady() && $user->isActive() && $brand !== null && $user->isMemberOf($brand);
    }

    /** The scopes this person may write in, in the order the form offers them. */
    public function scopesFor(User $user): array
    {
        $brand = app(CurrentBrand::class)->get();

        if (! $this->viewAny($user) || $brand === null) {
            return [];
        }

        return match (true) {
            $user->isGlobalAdmin() => [CannedReply::PERSONAL, CannedReply::BRAND, CannedReply::GLOBAL],
            $user->isManagerIn($brand) => [CannedReply::PERSONAL, CannedReply::BRAND],
            default => [CannedReply::PERSONAL],
        };
    }

    public function create(User $user): bool
    {
        return $this->scopesFor($user) !== [];
    }

    public function update(User $user, CannedReply $reply): bool
    {
        $brand = app(CurrentBrand::class)->get();

        if (! $this->viewAny($user) || $brand === null) {
            return false;
        }

        return match ($reply->scope) {
            CannedReply::GLOBAL => $user->isGlobalAdmin(),
            CannedReply::BRAND => $reply->brand_id === $brand->getKey() && ($user->isGlobalAdmin() || $user->isManagerIn($brand)),
            default => $reply->user_id === $user->getKey(),
        };
    }

    public function delete(User $user, CannedReply $reply): bool
    {
        return $this->update($user, $reply);
    }
}
