<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A browser's address for desktop alerts, as `PushSubscription.toJSON()`
 * gives it. The server will post to that address, so only the push services
 * of the browser makers are accepted, never an address somebody typed.
 */
final class PushSubscriptionRequest extends FormRequest
{
    /** Hosts of the push services of Chrome, Edge, Firefox and Safari (and the browsers built on them). */
    private const SERVICES = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        '.notify.windows.com',
        '.push.apple.com',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $writing = $this->isMethod('post');

        return [
            'endpoint' => ['required', 'string', 'max:2048', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isPushService((string) $value)) {
                    $fail('This browser\'s push service is not supported.');
                }
            }],
            'keys.p256dh' => $writing ? ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-=]+$/'] : ['nullable'],
            'keys.auth' => $writing ? ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-=]+$/'] : ['nullable'],
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }

    public static function isPushService(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        foreach (self::SERVICES as $service) {
            if (str_starts_with($service, '.') ? str_ends_with($host, $service) : $host === $service) {
                return true;
            }
        }

        return false;
    }
}
