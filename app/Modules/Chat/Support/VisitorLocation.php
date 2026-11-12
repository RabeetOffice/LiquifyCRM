<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Where a website visitor's address is (user request, 12 Nov 2026): country, region, city, network, time zone and whether
 * it is a VPN or proxy. Asked of FreeIPAPI (free, no key, business use allowed; named on the privacy page), only the
 * address is sent, once a week per address, never for a private or reserved address, and at most a few dozen times a
 * minute for the whole CRM. `liquify.chat.geo` = "none" switches it off. A failure only means no location is shown.
 */
final class VisitorLocation
{
    private const PER_MINUTE = 40;

    /**
     * @return array{country: ?string, region: ?string, city: ?string, isp: ?string, timezone: ?string, is_proxy: ?bool}|null
     */
    public static function lookup(?string $ip): ?array
    {
        if ($ip === null || config('liquify.chat.geo', 'freeipapi') !== 'freeipapi'
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $key = 'chat-geo:'.hash('sha256', $ip);
        $known = Cache::get($key);

        if (is_array($known)) {
            /** @var array{country: ?string, region: ?string, city: ?string, isp: ?string, timezone: ?string, is_proxy: ?bool} $known */
            return $known;
        }

        if (RateLimiter::tooManyAttempts('chat-geo', self::PER_MINUTE)) {
            return null;
        }

        RateLimiter::hit('chat-geo', 60);

        try {
            $response = Http::timeout(5)->connectTimeout(4)->retry(2, 400, throw: false)->acceptJson()->get('https://free.freeipapi.com/api/v1/json/'.rawurlencode($ip));
        } catch (Throwable) {
            return null;
        }

        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data) || preg_match('/^[A-Z]{2}$/', (string) ($data['countryCode'] ?? '')) !== 1) {
            return null;
        }

        $zones = is_array($data['timeZones'] ?? null) ? $data['timeZones'] : [];
        $text = fn (mixed $value, int $max): ?string => is_string($value) && trim($value) !== '' && trim($value) !== '-' ? mb_substr(trim(strip_tags($value)), 0, $max) : null;

        $found = [
            'country' => (string) $data['countryCode'],
            'region' => $text($data['regionName'] ?? null, 120),
            'city' => $text($data['cityName'] ?? null, 120),
            'isp' => $text($data['asnOrganization'] ?? null, 120),
            // A country of several time zones gives the visitor's own only when there is one.
            'timezone' => count($zones) === 1 && is_string($zones[0]) && in_array($zones[0], timezone_identifiers_list(), true) ? $zones[0] : null,
            // A VPN or proxy, or an address of a hosting company (a computer in a data centre: test tools, crawlers).
            'is_proxy' => (bool) ($data['isProxy'] ?? false) || preg_match('/amazon|aws|google cloud|google llc|microsoft|azure|digitalocean|hetzner|ovh|linode|akamai|vultr|choopa|oracle|cloudflare|alibaba|tencent|contabo|leaseweb|hostinger|m247|datacamp|cdn77|fastly/i', (string) ($data['asnOrganization'] ?? '')) === 1,
        ];

        Cache::put($key, $found, now()->addWeek());

        return $found;
    }

    /**
     * Whether the visitor is behind a VPN or proxy: the address lookup said so, the address belongs to a hosting company (a
     * computer in a data centre), or the visitor's own computer is set to the time of another country than the address
     * (Browsec and most VPNs: the address is abroad, the clock at home). `real` is the country the computer's clock
     * points to, when it differs.
     *
     * @return array{vpn: bool, real: ?string}
     */
    public static function vpn(?bool $isProxy, ?string $isp, ?string $country, ?string $browserTz): array
    {
        $real = null;

        if ($browserTz !== null && $browserTz !== '' && in_array($browserTz, timezone_identifiers_list(), true) && $country !== null) {
            $code = (new \DateTimeZone($browserTz))->getLocation()['country_code'] ?? '??';

            if (preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== strtoupper($country)) {
                $real = $code;
            }
        }

        $hosting = $isp !== null && preg_match('/amazon|aws|google cloud|microsoft|azure|digitalocean|hetzner|ovh|linode|akamai|vultr|choopa|oracle|alibaba|tencent|contabo|leaseweb|m247|datacamp|cdn77|fastly|hostinger/i', $isp) === 1;

        return ['vpn' => (bool) $isProxy || $hosting || $real !== null, 'real' => $real];
    }

    /** "Dublin, Leinster, Ireland" from what is known. */
    public static function label(?string $city, ?string $region, ?string $country): string
    {
        $name = $country !== null && preg_match('/^[A-Z]{2}$/', $country) === 1 ? (\Locale::getDisplayRegion('-'.$country, 'en') ?: $country) : null;

        return collect([$city, $region !== $city ? $region : null, $name])->filter()->implode(', ');
    }
}
