<?php

declare(strict_types=1);

namespace App\Modules\Chat\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Core\Support\CurrentBrand;

/**
 * Admins and the brand's Managers set up its live chat; Agents only work the chats (user's plan, 3 Nov 2026).
 */
final class ChatWidgetPolicy
{
    public function viewAny(User $user): bool
    {
        $brand = app(CurrentBrand::class)->get();

        return LiveChat::ready() && $user->isActive() && $brand !== null && ($user->isGlobalAdmin() || $user->isManagerIn($brand));
    }

    public function view(User $user, ChatWidget $widget): bool
    {
        return $this->viewAny($user) && app(CurrentBrand::class)->is($widget->brand_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ChatWidget $widget): bool
    {
        return $this->view($user, $widget);
    }

    public function delete(User $user, ChatWidget $widget): bool
    {
        return $this->view($user, $widget);
    }
}
