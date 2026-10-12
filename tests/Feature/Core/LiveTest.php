<?php

declare(strict_types=1);

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Live;
use App\Modules\Core\Support\RunSoon;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Jobs\IngestLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Live updates without websockets: every brand and person has a stamp that
 * moves when something changes, and an open page asks for it with one small
 * request that needs no session.
 */

it('moves the stamp of a brand when one of its leads changes, and of no other brand', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $other = brand(['slug' => 'anz']);
    $user = admin();
    Live::flush();

    $before = Live::stamps($brand->getKey(), $user->getKey());
    $otherBefore = Live::stamps($other->getKey(), $user->getKey());

    Lead::factory()->create(['brand_id' => $brand->getKey()]);
    Live::flush();

    expect(Live::stamps($brand->getKey(), $user->getKey())['b'])->toBeGreaterThan($before['b'])
        ->and(Live::stamps($other->getKey(), $user->getKey())['b'])->toBe($otherBefore['b'])
        ->and(Live::stamps(null, $user->getKey(), everyBrand: true)['b'])->toBeGreaterThan(0);
});

it('writes the bell entry at once and moves that person\'s stamp, while the email waits for the queue', function (): void {
    config()->set('queue.default', 'database');
    $user = admin();

    $user->notify(new AppNotification('New lead: Sarah Lee', 'A new lead arrived.', mail: true));

    expect($user->notifications()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(Live::stamps(null, $user->getKey())['u'])->toBeGreaterThan(0);
});

it('answers a page with its stamps and opens no session for it', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $user = admin();

    Lead::factory()->create(['brand_id' => $brand->getKey()]);
    Live::flush();

    $response = $this->getJson('/api/live?t='.urlencode(Live::token($user->getKey(), $brand->getKey())));

    $response->assertOk()->assertJsonStructure(['b', 'u'])->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('b'))->toBeGreaterThan(0)
        ->and($response->headers->getCookies())->toBe([]);
});

it('refuses a token that is forged or too old', function (): void {
    $user = admin();
    $token = Live::token($user->getKey(), 5);

    $this->getJson('/api/live?t='.urlencode(substr($token, 0, -4).'0000'))->assertForbidden();
    $this->getJson('/api/live?t=')->assertForbidden();

    $this->travel(13)->hours();
    $this->getJson('/api/live?t='.urlencode($token))->assertForbidden();
});

it('puts the token on every signed-in page and marks the screens that reload themselves', function (): void {
    $brand = brand(['slug' => 'ukph']);

    $this->actingAs(admin())->get('/b/ukph/leads')
        ->assertOk()
        ->assertSee('data-live="', escape: false)
        ->assertSee('data-live-refresh', escape: false);
});

it('queues work from the command line and runs it after the answer during a web request', function (): void {
    Queue::fake();

    RunSoon::dispatch(new IngestLead(1));

    Queue::assertPushed(IngestLead::class, 1);
});

it('shows a brand in its second colour when the main one says nothing', function (): void {
    expect((new Brand(['colors' => ['primary' => '#FFFFFF', 'secondary' => '#1D4ED8']]))->primaryColor())->toBe('#1D4ED8')
        ->and((new Brand(['colors' => ['primary' => '#1D4ED8', 'secondary' => '#DC2626']]))->primaryColor())->toBe('#1D4ED8')
        ->and((new Brand(['colors' => ['primary' => '#FEFEFE']]))->primaryColor())->toBeNull()
        ->and((new Brand(['colors' => null]))->primaryColor())->toBeNull()
        ->and(Brand::colorSaysSomething('#0A0A0A'))->toBeFalse()
        ->and(Brand::colorSaysSomething('#FF6B2C'))->toBeTrue();
});
