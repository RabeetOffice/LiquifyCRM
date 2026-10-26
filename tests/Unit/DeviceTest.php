<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\DeviceKind;
use App\Modules\Auth\Support\Device;
use App\Modules\Auth\Support\SignInRules;
use Illuminate\Http\Request;

/*
 * What somebody signs in on, from the browser's own words and what the
 * sign-in page saw of the screen.
 */

function deviceOf(string $agent, ?array $hint = null, array $headers = []): Device
{
    $request = Request::create('/login', 'POST', server: ['HTTP_USER_AGENT' => $agent, ...collect($headers)->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all()]);

    return Device::of($request, $hint !== null ? json_encode($hint) : null);
}

it('tells phones, tablets and computers apart', function (string $agent, ?array $hint, DeviceKind $kind, string $name): void {
    $device = deviceOf($agent, $hint);

    expect($device->kind)->toBe($kind)->and($device->name)->toBe($name);
})->with([
    'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', null, DeviceKind::Phone, 'iPhone · Safari'],
    'Android phone' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', null, DeviceKind::Phone, 'Android phone (SM-S918B) · Chrome'],
    'Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', null, DeviceKind::Tablet, 'Android tablet (SM-X710) · Chrome'],
    'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36', null, DeviceKind::Phone, 'Android phone · Samsung Internet'],
    'iPad as a Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', ['touch' => 5, 'w' => 820, 'h' => 1180], DeviceKind::Tablet, 'iPad · Safari'],
    'iPhone asking for the desktop site' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', ['touch' => 5, 'w' => 390, 'h' => 844], DeviceKind::Phone, 'iPhone · Safari'],
    'Android asking for the desktop site' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', ['touch' => 5, 'w' => 412, 'h' => 915], DeviceKind::Phone, 'Phone · Chrome'],
    'Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', ['touch' => 0, 'w' => 1512, 'h' => 982], DeviceKind::Computer, 'Mac · Safari'],
    'Windows with a touch screen' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', ['touch' => 10, 'w' => 1920, 'h' => 1080], DeviceKind::Computer, 'Windows · Edge'],
    'Chromebook' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', ['touch' => 10, 'w' => 1366, 'h' => 768], DeviceKind::Computer, 'Chromebook · Chrome'],
]);

it('believes a browser that says it is a phone', function (): void {
    expect(deviceOf('Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0.0.0 Safari/537.36', headers: ['Sec-CH-UA-Mobile' => '?1'])->kind)->toBe(DeviceKind::Phone);
});

it('reads typed addresses and ranges, and matches them', function (): void {
    expect(SignInRules::normalise(' 203.0.113.7 '))->toBe('203.0.113.7')
        ->and(SignInRules::normalise('203.0.113.0/24'))->toBe('203.0.113.0/24')
        ->and(SignInRules::normalise('2001:db8::/64'))->toBe('2001:db8::/64')
        ->and(SignInRules::normalise('203.0.113.0/4'))->toBeNull()
        ->and(SignInRules::normalise('office'))->toBeNull()
        ->and(SignInRules::matches('203.0.113.77', ['203.0.113.0/24']))->toBeTrue()
        ->and(SignInRules::matches('203.0.114.1', ['203.0.113.0/24']))->toBeFalse()
        ->and(SignInRules::matches('2001:db8::abcd', ['2001:db8::/64']))->toBeTrue()
        ->and(SignInRules::matches(null, ['203.0.113.0/24']))->toBeFalse();
});
