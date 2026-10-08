<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Auth\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Component;

/** The in-app bell: latest database notifications with mark-as-read. */
final class NotificationBell extends Component
{
    public bool $open = false;

    public function markRead(string $id): void
    {
        $this->user()->notifications()->whereKey($id)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = $this->user();

        /** @var Collection<int, DatabaseNotification> $notifications */
        $notifications = $this->open ? $user->notifications()->latest()->limit(12)->get() : collect();

        return view('core::livewire.notification-bell', [
            'unread' => $user->unreadNotifications()->count(),
            'notifications' => $notifications,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
