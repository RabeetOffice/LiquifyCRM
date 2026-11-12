<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Chat\Models\ChatWidget;
use Illuminate\Http\Request;
use WeakMap;

/**
 * The chat served from the brand's own website (user request, 12 Nov 2026: "hide luminocrm.online from the script").
 * liquify-chat.php on the website passes the chat's calls to LiquifyCRM server to server, so the visitor's browser only
 * talks to the brand's domain. Every call it passes carries the visitor's address and the file's own address, signed
 * with the widget's proxy secret (5 minutes). Only a call that checks out is believed: then the visitor's address is the
 * one the file saw (limits, location, spam checks), and every address LiquifyCRM hands back to the browser (the chat
 * window, its calls, files) goes through the file. Without a valid signature nothing changes.
 */
final class ChatProxy
{
    private const MAX_AGE = 300;

    /** @var WeakMap<Request, array{ip: string, base: string, widget: int}|false>|null */
    private static ?WeakMap $seen = null;

    /** The visitor's address: the one liquify-chat.php saw when the call came through it, else the caller's. */
    public static function ip(Request $request): string
    {
        $proxy = self::of($request);

        return $proxy !== null ? $proxy['ip'] : (string) $request->ip();
    }

    /** The address of liquify-chat.php on the website when this call came through it, else null. */
    public static function base(Request $request): ?string
    {
        return self::of($request)['base'] ?? null;
    }

    /**
     * A LiquifyCRM chat address ("https://crm/chat/frame/wk_…?o=…") as the browser must use it: through the website's file
     * when the call came through it ("https://brand/liquify-chat.php?p=frame/wk_…&o=…"), unchanged otherwise.
     */
    public static function url(Request $request, string $url): string
    {
        $base = self::base($request);
        $prefix = rtrim(url('/'), '/').'/chat/';

        if ($base === null || ! str_starts_with($url, $prefix)) {
            return $url;
        }

        [$path, $query] = array_pad(explode('?', substr($url, strlen($prefix)), 2), 2, '');

        return $base.'?p='.$path.($query !== '' ? '&'.$query : '');
    }

    /** @return array{ip: string, base: string, widget: int}|null */
    public static function of(Request $request): ?array
    {
        self::$seen ??= new WeakMap;

        if (! isset(self::$seen[$request])) {
            self::$seen[$request] = self::check($request) ?? false;
        }

        $found = self::$seen[$request];

        return $found === false ? null : $found;
    }

    /** @return array{ip: string, base: string, widget: int}|null */
    private static function check(Request $request): ?array
    {
        $key = (string) $request->header('X-Liquify-Proxy-Key');
        $ip = (string) $request->header('X-Liquify-Proxy-Ip');
        $base = (string) $request->header('X-Liquify-Proxy-Base');
        $time = (string) $request->header('X-Liquify-Proxy-Ts');
        $signature = (string) $request->header('X-Liquify-Proxy-Sig');

        if ($key === '' || $signature === '' || preg_match('/^wk_[A-Za-z0-9]{24}$/', $key) !== 1 || ! ctype_digit($time)
            || abs(time() - (int) $time) > self::MAX_AGE || filter_var($ip, FILTER_VALIDATE_IP) === false || ! LiveChat::proxyReady()) {
            return null;
        }

        $widget = ChatWidget::query()->withoutGlobalScopes()->where('widget_key', $key)->where('is_active', true)->first();
        $secret = $widget?->proxy_secret;

        if ($widget === null || ! is_string($secret) || $secret === '' || ! hash_equals(hash_hmac('sha256', $key.'|'.$ip.'|'.$base.'|'.$time, $secret), $signature)) {
            return null;
        }

        // The file must sit on one of the widget's websites, at a plain .php address.
        $parts = parse_url($base);
        $origin = is_array($parts) ? (($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')) : '';

        if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment']) || preg_match('~^/[A-Za-z0-9._/-]{0,200}\.php$~', (string) ($parts['path'] ?? '')) !== 1
            || $widget->originFor($origin) === null) {
            return null;
        }

        return ['ip' => $ip, 'base' => $widget->originFor($origin).$parts['path'], 'widget' => (int) $widget->getKey()];
    }
}
