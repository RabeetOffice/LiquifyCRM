<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Contracts\PushTransport;
use App\Modules\Core\Enums\PushResult;
use App\Modules\Core\Models\PushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as Sender;

/**
 * Encrypts a desktop alert for one browser and hands it to that browser's
 * push service (Google for Chrome, Microsoft for Edge, Mozilla, Apple), signed
 * with LiquifyCRM's key pair. The service only sees an encrypted blob.
 */
final class WebPushTransport implements PushTransport
{
    /** An alert older than this is no longer news: the push service drops it. */
    private const KEEP_SECONDS = 4 * 3600;

    private ?Sender $sender = null;

    public function deliver(PushSubscription $subscription, string $payload): PushResult
    {
        $sender = $this->sender();

        if ($sender === null) {
            return PushResult::Failed;
        }

        $report = $sender->sendOneNotification(Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            'contentEncoding' => $subscription->content_encoding,
        ]), $payload);

        if ($report->isSuccess()) {
            return PushResult::Delivered;
        }

        $status = $report->getResponse()?->getStatusCode();

        // 404 and 410: the browser dropped this address. 403: it was made with another key pair, so it can never work again.
        if ($report->isSubscriptionExpired() || $status === 403) {
            return PushResult::Gone;
        }

        Log::warning('Desktop alerts: a push service refused an alert.', [
            'service' => parse_url($subscription->endpoint, PHP_URL_HOST),
            'status' => $status,
            'reason' => $report->getReason(),
        ]);

        return PushResult::Failed;
    }

    private function sender(): ?Sender
    {
        if ($this->sender !== null) {
            return $this->sender;
        }

        $keys = WebPush::keys();

        if ($keys === null) {
            return null;
        }

        return $this->sender = new Sender(
            ['VAPID' => ['subject' => WebPush::subject(), 'publicKey' => $keys['public'], 'privateKey' => $keys['private']]],
            ['TTL' => self::KEEP_SECONDS, 'urgency' => 'high'],
            // Never let a slow push service hold the request it runs after.
            new Client(['timeout' => 8, 'connect_timeout' => 4]),
        );
    }
}
