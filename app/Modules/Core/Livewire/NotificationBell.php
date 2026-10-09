<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The in-app bell: latest database notifications with mark-as-read. It
 * checks for news every 15 seconds; when something new has arrived since
 * the last look, it also shows as a toast so nobody has to reload the page.
 */
final class NotificationBell extends Component
{
    use WithToasts;

    public bool $open = false;

    /** Unread count at the last check; -1 before the first one. */
    public int $seen = -1;

    public function mount(): void
    {
        $this->seen = $this->user()->unreadNotifications()->count();
    }

    /** Called by the poll: announce what arrived since the last check. */
    public function tick(): void
    {
        $user = $this->user();
        $unread = $user->unreadNotifications()->count();

        if ($this->seen >= 0 && $unread > $this->seen) {
            $arrived = $unread - $this->seen;

            /** @var DatabaseNotification|null $latest */
            $latest = $user->unreadNotifications()->latest()->first();
            $data = $latest !== null ? (array) $latest->data : [];

            $this->toast(
                $arrived === 1 ? (string) ($data['title'] ?? 'New notification') : $arrived.' new notifications',
                $arrived === 1 ? ($data['body'] ?? null) : (string) ($data['title'] ?? ''),
                'info',
            );
        }

        $this->seen = $unread;
    }

    public function markRead(string $id): void
    {
        $this->user()->notifications()->whereKey($id)->update(['read_at' => now()]);
        $this->seen = $this->user()->unreadNotifications()->count();
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->seen = 0;
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
