<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * The bell (with its sound, desktop alert and push, no email) for the people who answer a website chat: the
 * team when a visitor starts a chat nobody looks after, the person who looks after it when the visitor writes
 * again. One entry per chat every two minutes, so a visitor who sends five lines rings once.
 */
final class ChatNotifier
{
    private const QUIET_SECONDS = 120;

    /** A visitor wrote: tell whoever should answer, unless they were told a moment ago. */
    public function visitorWrote(Conversation $conversation, string $text, bool $started): void
    {
        if (! Cache::add('chat:told:'.$conversation->getKey(), true, self::QUIET_SECONDS)) {
            return;
        }

        $recipients = $this->recipients($conversation);

        if ($recipients->isEmpty()) {
            return;
        }

        $brand = Brand::query()->find($conversation->brand_id);

        if ($brand === null) {
            return;
        }

        Notification::send($recipients, new AppNotification(
            ($started ? 'New live chat: ' : 'Live chat: ').$conversation->displayName(),
            Str::limit((string) Str::of($text)->squish(), 110),
            'info',
            $brand->url('live-chat/chats?chat='.$conversation->getKey()),
            mail: false,
            icon: 'heroicon-o-chat-bubble-oval-left-ellipsis',
            actionLabel: 'Open chat',
        ));
    }

    /** A chat was given to somebody. */
    public function assigned(Conversation $conversation, User $to, ?User $by): void
    {
        $brand = Brand::query()->find($conversation->brand_id);

        if ($brand === null || $by?->is($to) === true) {
            return;
        }

        Notification::send($to, new AppNotification(
            'Live chat given to you: '.$conversation->displayName(),
            ($by->name ?? 'LiquifyCRM').' gave you this chat.',
            'info',
            $brand->url('live-chat/chats?chat='.$conversation->getKey()),
            mail: false,
            icon: 'heroicon-o-user-plus',
            actionLabel: 'Open chat',
        ));
    }

    /**
     * The person who looks after the chat; with nobody on it, those who may take it: a lead's team (its Managers, or
     * the Admins), a visitor's chat goes to everybody who sees visitors.
     *
     * @return Collection<int, User>
     */
    private function recipients(Conversation $conversation): Collection
    {
        $lead = $conversation->lead_id !== null ? Lead::query()->find($conversation->lead_id) : null;
        // A chat with a lead goes by the lead; a visitor's chat by the person who took it.
        $owner = $lead !== null ? $lead->assigned_to : $conversation->chat_assigned_to;

        if ($owner !== null) {
            return User::query()->whereKey($owner)->get()->filter(fn (User $user): bool => $user->isActive());
        }

        if ($lead !== null) {
            $department = $lead->department()->first();
            $people = $department !== null
                ? $department->managers()->get()
                : User::query()->whereIn('role', [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value])->get();

            return $people->filter(fn (User $user): bool => $user->isActive());
        }

        $ids = Membership::query()->acrossBrands()
            ->where('brand_id', $conversation->brand_id)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('user_id');

        return User::query()->whereIn('id', $ids)->get()
            ->filter(fn (User $user): bool => $user->isActive() && ChatAccess::canSeeVisitors($user, (int) $conversation->brand_id));
    }
}
