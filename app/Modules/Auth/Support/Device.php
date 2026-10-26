<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Enums\DeviceKind;
use Illuminate\Http\Request;

/**
 * What somebody signs in on, read from the browser: its user agent, the
 * Sec-CH-UA-Mobile hint and, on the sign-in form, what the page itself saw
 * (touch points and screen size, the `device` field filled by the form). A
 * phone asking for the "desktop site" calls itself a computer in its user
 * agent but still has a small touch screen, and an iPad calls itself a Mac
 * with touch. A person who fakes all of it in a developer tool gets past
 * this; the office network rule is the firm one.
 */
final readonly class Device
{
    public function __construct(
        public DeviceKind $kind,
        public string $name,
    ) {}

    public static function of(Request $request, ?string $hint = null): self
    {
        $agent = (string) $request->userAgent();

        return new self(self::kindOf($agent, $request->header('Sec-CH-UA-Mobile'), self::hint($hint)), self::nameOf($agent, $hint));
    }

    /** @param  array{touch: int, short: int, long: int, mobile: bool|null}|null  $hint */
    private static function kindOf(string $agent, ?string $mobileHeader, ?array $hint): DeviceKind
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|Kindle|Silk|PlayBook/i', $agent) => DeviceKind::Tablet,
            (bool) preg_match('/Android/i', $agent) => str_contains($agent, 'Mobile') ? DeviceKind::Phone : DeviceKind::Tablet,
            (bool) preg_match('/iPhone|iPod|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile|Mobi/i', $agent) => DeviceKind::Phone,
            trim((string) $mobileHeader) === '?1', ($hint['mobile'] ?? null) === true => DeviceKind::Phone,
            // A "desktop site" phone or an iPad: a Mac or Linux that can be touched (a Chromebook says CrOS).
            $hint !== null && $hint['touch'] > 1 && (bool) preg_match('/Macintosh|Linux|X11/i', $agent) && ! str_contains($agent, 'CrOS') => $hint['short'] > 0 && $hint['short'] < 600 ? DeviceKind::Phone : DeviceKind::Tablet,
            default => DeviceKind::Computer,
        };
    }

    /** "iPhone · Safari", "Android phone (SM-S918B) · Chrome", "Windows · Edge". */
    private static function nameOf(string $agent, ?string $hint): string
    {
        $kind = self::kindOf($agent, null, self::hint($hint));

        $model = preg_match('/Android [\d.]+; ([^;)]+)/', $agent, $match) && ! in_array(trim($match[1]), ['K', 'wv'], true) ? ' ('.trim($match[1]).')' : '';

        $device = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPod') => 'iPod',
            str_contains($agent, 'iPad'), $kind === DeviceKind::Tablet && str_contains($agent, 'Macintosh') => 'iPad',
            $kind === DeviceKind::Phone && str_contains($agent, 'Macintosh') => 'iPhone',
            str_contains($agent, 'Android') => ($kind === DeviceKind::Tablet ? 'Android tablet' : 'Android phone').$model,
            $kind->isMobile() => $kind->getLabel(),
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'CrOS') => 'Chromebook',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg') => 'Edge',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'OPR') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'CriOS') || str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => null,
        };

        return mb_substr($device.($browser !== null ? ' · '.$browser : ''), 0, 120);
    }

    /**
     * What the sign-in page saw, sent as JSON in its `device` field.
     *
     * @return array{touch: int, short: int, long: int, mobile: bool|null}|null
     */
    private static function hint(?string $hint): ?array
    {
        $data = $hint !== null && $hint !== '' ? json_decode(mb_substr($hint, 0, 500), true) : null;

        if (! is_array($data)) {
            return null;
        }

        $width = max(0, (int) ($data['w'] ?? 0));
        $height = max(0, (int) ($data['h'] ?? 0));

        return [
            'touch' => max(0, (int) ($data['touch'] ?? 0)),
            'short' => min($width, $height),
            'long' => max($width, $height),
            'mobile' => isset($data['mobile']) && is_bool($data['mobile']) ? $data['mobile'] : null,
        ];
    }
}
