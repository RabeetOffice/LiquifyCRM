<?php

declare(strict_types=1);

namespace App\Modules\Core\Notifications;

use App\Modules\Auth\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification shape for the in-app bell (database channel) and,
 * when asked, the matching email: a title, one line, a status colour and
 * a link to the screen where the person acts.
 */
final class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $status = 'info',
        public readonly ?string $url = null,
        public readonly bool $mail = false,
        public readonly ?string $icon = null,
        public readonly string $actionLabel = 'Open',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->mail ? ['database', 'mail'] : ['database'];
    }

    /**
     * The bell entry is written at once, in the request that caused it, so it
     * shows within seconds; only the email waits for the queue.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'url' => $this->url,
            'icon' => $this->icon,
            'action_label' => $this->actionLabel,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->title)->line($this->body);

        if ($this->url !== null) {
            $message->action($this->actionLabel, $this->url);
        }

        return $message;
    }
}
