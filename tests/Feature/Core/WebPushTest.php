<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\PushTransport;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\PushResult;
use App\Modules\Core\Models\PushSubscription;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Settings\PushSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Desktop alerts with LiquifyCRM closed (Web Push, 14 October): a browser
 * gives the address of its push service, every new bell entry of its person is
 * sent there, a browser that is gone is forgotten, and signing out stops it.
 */

/** A browser subscription as PushSubscription.toJSON() gives it. */
function pushSubscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
    ];
}

beforeEach(function (): void {
    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->agent = agentOf($this->department, ['name' => 'Aisha Agent']);
    $this->other = memberOf($this->brand, BrandRole::Agent, null, ['name' => 'Omar Agent']);

    // A fixed pair made for these tests, so no key is made here (Windows PHP needs OpenSSL's settings file for that).
    $settings = app(PushSettings::class);
    $settings->public_key = 'BJ_ZLtjiUMqGemfBolYNa_Zl62f1mtp93LcjhwRnw0XlV99rYDfgp4lJHwKxe9cvIGOK8F8ROOsPNCEKIa2CBJ8';
    $settings->private_key = '9wmPz0V6xO-4WUT71voEh7CLNrlD06kvNnn9C16dhBY';
    $settings->save();

    // Every alert is handed to this instead of a real push service.
    $this->sent = collect();
    $this->results = [];
    $test = $this;
    app()->instance(PushTransport::class, new class($test) implements PushTransport
    {
        public function __construct(private readonly object $test) {}

        public function deliver(PushSubscription $subscription, string $payload): PushResult
        {
            $this->test->sent->push(['endpoint' => $subscription->endpoint, 'payload' => json_decode($payload, true)]);

            return $this->test->results[$subscription->endpoint] ?? PushResult::Delivered;
        }
    });
});

it('keeps a browser\'s address for desktop alerts, encrypted, and moves it to whoever signs in there next', function (): void {
    $this->actingAs($this->agent)->postJson('/push/subscription', pushSubscription())->assertNoContent();

    $row = PushSubscription::query()->sole();
    expect($row->user_id)->toBe($this->agent->getKey())
        ->and($row->auth_token)->toBe('tBHItJI5svbpez7KI4CCXg')
        ->and(DB::table('push_subscriptions')->value('auth_token'))->not->toContain('tBHItJI5svbpez7KI4CCXg');

    // The same browser, now signed in as somebody else.
    $this->actingAs($this->other)->postJson('/push/subscription', pushSubscription())->assertNoContent();
    expect(PushSubscription::query()->sole()->user_id)->toBe($this->other->getKey());
});

it('accepts only the push services of the browser makers', function (string $endpoint): void {
    $this->actingAs($this->agent)->postJson('/push/subscription', pushSubscription($endpoint))->assertUnprocessable();

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'another site' => 'https://evil.test/fcm.googleapis.com/send/1',
    'a look-alike host' => 'https://fcm.googleapis.com.evil.test/send/1',
    'not https' => 'http://fcm.googleapis.com/fcm/send/1',
]);

it('takes the address back when desktop alerts are turned off or the person signs out', function (): void {
    $this->actingAs($this->agent)->postJson('/push/subscription', pushSubscription())->assertNoContent();

    // Somebody else cannot take it away.
    $this->actingAs($this->other)->deleteJson('/push/subscription', ['endpoint' => pushSubscription()['endpoint']])->assertNoContent();
    expect(PushSubscription::query()->count())->toBe(1);

    $this->actingAs($this->agent)->deleteJson('/push/subscription', ['endpoint' => pushSubscription()['endpoint']])->assertNoContent();
    expect(PushSubscription::query()->count())->toBe(0);

    // Signing out forgets this browser's address.
    $this->actingAs($this->agent)->postJson('/push/subscription', pushSubscription())->assertNoContent();
    $this->post('/logout');
    expect(PushSubscription::query()->count())->toBe(0);
});

it('sends a new bell entry to the person\'s browsers and forgets the ones that are gone', function (): void {
    $this->actingAs($this->agent)->postJson('/push/subscription', pushSubscription('https://fcm.googleapis.com/fcm/send/laptop'));
    $this->actingAs($this->agent)->postJson('/push/subscription', pushSubscription('https://wns2-par02p.notify.windows.com/w/?token=old'));
    $this->results['https://wns2-par02p.notify.windows.com/w/?token=old'] = PushResult::Gone;

    $this->agent->notify(new AppNotification('New lead: Sarah Lee', 'Website · UK Publishing House', 'info', 'https://crm.test/b/ukph/leads/7'));

    $entry = $this->agent->notifications()->sole();
    expect($this->sent)->toHaveCount(2)
        ->and($this->sent->first()['payload'])->toBe([
            'id' => $entry->getKey(),
            'title' => 'New lead: Sarah Lee',
            'body' => 'Website · UK Publishing House',
            'url' => 'https://crm.test/b/ukph/leads/7',
        ])
        ->and(PushSubscription::query()->pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/laptop'])
        ->and(PushSubscription::query()->sole()->last_used_at)->not->toBeNull();

    // Somebody without a browser asking for alerts sends nothing; an entry already read is not announced.
    $this->other->notify(new AppNotification('New lead: Tom', 'Website', 'info'));
    $this->agent->notifications()->create(['id' => (string) Str::uuid(), 'type' => AppNotification::class, 'data' => ['title' => 'Old news'], 'read_at' => now()]);

    expect($this->sent)->toHaveCount(2);
});

it('puts the public key on the page for the browser to subscribe with', function (): void {
    $this->actingAs($this->agent)->get('/b/ukph')
        ->assertOk()
        ->assertSee('<meta name="lq-push-key" content="BJ_ZLtjiUMqGemfBolYNa_Zl62f1mtp93LcjhwRnw0XlV99rYDfgp4lJHwKxe9cvIGOK8F8ROOsPNCEKIa2CBJ8">', false);

    // Signed out there is nothing to subscribe for.
    $this->post('/logout');
    $this->get('/login')->assertOk()->assertDontSee('lq-push-key', false);
});
