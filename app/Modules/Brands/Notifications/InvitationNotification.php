<?php

declare(strict_types=1);

namespace App\Modules\Brands\Notifications;

use App\Modules\Brands\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invitation $invitation) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['brand', 'department', 'inviter']);
        $brand = $invitation->brand;
        $inviter = $invitation->inviter->name ?? 'Your team';

        $message = (new MailMessage)
            ->subject("You're invited to {$brand->name} on LiquifyCRM")
            ->greeting('Hello'.($invitation->name ? ' '.$invitation->name : '').',')
            ->line("{$inviter} has invited you to join {$brand->name} as a {$invitation->role->getLabel()}.");

        if ($invitation->department !== null) {
            $message->line("You will work in the {$invitation->department->name} department.");
        }

        return $message
            ->action('Accept invitation', $invitation->acceptUrl())
            ->line('This link works for '.config('liquify.invitation_expiry_days').' days. If you did not expect it, you can ignore this email.');
    }
}
