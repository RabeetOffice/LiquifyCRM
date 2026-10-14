<?php

declare(strict_types=1);

namespace App\Modules\Core\Jobs;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Contracts\PushTransport;
use App\Modules\Core\Enums\PushResult;
use App\Modules\Core\Models\PushSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one new bell entry to every browser where its person turned desktop
 * alerts on (Web Push). Runs right after the answer (RunSoon); one try, since
 * an alert that comes late is no alert. The tag is the bell entry's id, the
 * same one an open page uses, so the browser never shows it twice.
 */
final class SendWebPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $notificationId) {}

    public function handle(PushTransport $transport): void
    {
        $notification = DatabaseNotification::query()->find($this->notificationId);

        // Already read (somebody was looking at it) or gone: nothing to announce.
        if (! $notification instanceof DatabaseNotification || $notification->read_at !== null
            || $notification->getAttribute('notifiable_type') !== (new User)->getMorphClass()) {
            return;
        }

        $data = (array) $notification->data;
        $payload = (string) json_encode([
            'id' => (string) $notification->getKey(),
            'title' => Str::limit((string) ($data['title'] ?? 'New notification'), 120),
            'body' => Str::limit((string) ($data['body'] ?? ''), 240),
            'url' => isset($data['url']) ? (string) $data['url'] : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $subscriptions = PushSubscription::query()->where('user_id', (int) $notification->getAttribute('notifiable_id'))->get();

        foreach ($subscriptions as $subscription) {
            try {
                $result = $transport->deliver($subscription, $payload);
            } catch (Throwable $exception) {
                Log::warning('Desktop alerts: an alert could not be sent.', [
                    'service' => parse_url($subscription->endpoint, PHP_URL_HOST),
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            match ($result) {
                PushResult::Delivered => $subscription->forceFill(['last_used_at' => now()])->save(),
                PushResult::Gone => $subscription->delete(),
                PushResult::Failed => null,
            };
        }
    }
}
