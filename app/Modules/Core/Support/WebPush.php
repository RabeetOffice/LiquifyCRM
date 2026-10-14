<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\PushSubscription;
use App\Modules\Core\Settings\PushSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Desktop alerts with LiquifyCRM closed (Web Push). A browser where somebody
 * turned desktop alerts on gives its push service's address (PushSubscription);
 * every new bell entry of that person is then sent there, encrypted, right after
 * the answer (SendWebPush), and the browser's service worker (public/sw.js)
 * shows it. The table comes with an update: until an Admin clicks "Update
 * system" this stays off and the alerts work as before, while a page is open.
 */
final class WebPush
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('push_subscriptions');
    }

    /** Whether a new bell entry of this person has anywhere to go. */
    public static function wanted(int $userId): bool
    {
        return self::ready() && PushSubscription::query()->where('user_id', $userId)->exists();
    }

    /** The public half of the key pair, for the page; null while push is off or no keys can be made. */
    public static function publicKey(): ?string
    {
        return self::ready() ? (self::keys()['public'] ?? null) : null;
    }

    /**
     * The key pair that signs every push, made the first time it is asked for.
     *
     * @return array{public: string, private: string}|null
     */
    public static function keys(): ?array
    {
        $settings = app(PushSettings::class);

        if (filled($settings->public_key) && filled($settings->private_key)) {
            return ['public' => (string) $settings->public_key, 'private' => (string) $settings->private_key];
        }

        // This server could not make them lately (OpenSSL without its settings file): no new try on every page.
        if (Cache::has('push-keys-failed')) {
            return null;
        }

        try {
            // Two first pages at the same moment must not make two different pairs.
            return Cache::lock('push-keys', 10)->block(5, function () use ($settings): array {
                $settings->refresh();

                return self::make($settings);
            });
        } catch (Throwable $exception) {
            Cache::put('push-keys-failed', true, now()->addHour());
            Log::warning('Desktop alerts: the Web Push keys could not be made.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /** Who the push services may contact about these messages. */
    public static function subject(): string
    {
        $email = (string) config('liquify.legal.email');

        return $email !== '' ? 'mailto:'.$email : (string) config('app.url');
    }

    /** @return array{public: string, private: string} */
    private static function make(PushSettings $settings): array
    {
        if (filled($settings->public_key) && filled($settings->private_key)) {
            return ['public' => (string) $settings->public_key, 'private' => (string) $settings->private_key];
        }

        $pair = VAPID::createVapidKeys();

        $settings->public_key = $pair['publicKey'];
        $settings->private_key = $pair['privateKey'];
        $settings->save();

        return ['public' => $pair['publicKey'], 'private' => $pair['privateKey']];
    }
}
