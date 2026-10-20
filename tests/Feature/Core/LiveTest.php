<?php

declare(strict_types=1);

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Core\Support\Live;
use App\Modules\Core\Support\RunSoon;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Sites\Jobs\IngestLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** A job that changes a brand a moment after the request did (as ProcessMetaEvent saving a message). */
final class TouchBrandAgain
{
    public function __construct(public int $brandId) {}

    public function handle(): void
    {
        usleep(3000);
        Live::touch($this->brandId);
    }
}

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

it('tells a page which stamp it was drawn with, so a page fetched early reloads when a lead came in since', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $admin = admin();

    Lead::factory()->create(['brand_id' => $brand->getKey()]);
    Live::flush();

    $everyBrand = Live::stamps(null, $admin->getKey(), everyBrand: true)['b'];

    expect($everyBrand)->toBeGreaterThan(0);

    // The admin area counts every brand's changes; a brand's page counts its own.
    $this->actingAs($admin)->get('/admin/brands')->assertOk()->assertSee('data-live-at="'.$everyBrand.'"', escape: false);
    $this->get('/b/ukph/leads')->assertOk()->assertSee('data-live-at="'.Live::stamps($brand->getKey(), $admin->getKey())['b'].'"', escape: false);
});

it('queues work from the command line and runs it after the answer during a web request', function (): void {
    Queue::fake();

    RunSoon::dispatch(new IngestLead(1));

    Queue::assertPushed(IngestLead::class, 1);
});

it('moves only the chat stamp for a message or a chat, so the other screens of the brand stay still', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $user = admin();
    inBrand($brand);
    $lead = Lead::factory()->create(['brand_id' => $brand->getKey()]);
    Live::forget();
    Live::flush();

    $before = Live::stamps($brand->getKey(), $user->getKey());
    usleep(2000);

    $conversation = Conversation::query()->create(['brand_id' => $brand->getKey(), 'lead_id' => $lead->getKey(), 'contact_external_id' => 'u1']);
    Message::query()->create(['conversation_id' => $conversation->getKey(), 'external_id' => 'm1', 'direction' => Message::IN, 'body' => 'Hello', 'status' => 'delivered']);
    Live::flush();

    $after = Live::stamps($brand->getKey(), $user->getKey());

    expect($after['c'])->toBeGreaterThan($before['c'])
        ->and($after['b'])->toBe($before['b']);

    // A lead still moves the brand's stamp.
    Live::forget();
    usleep(2000);
    $lead->forceFill(['name' => 'Renamed'])->save();
    Live::flush();

    expect(Live::stamps($brand->getKey(), $user->getKey())['b'])->toBeGreaterThan($after['b'])
        ->and($this->getJson('/api/live?t='.urlencode(Live::token($user->getKey(), $brand->getKey())))->assertOk()->json('c'))->toBe($after['c']);
});

it('tells the screens about what a job run after the answer changed, even right after another change', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $user = admin();
    $console = new ReflectionProperty($this->app, 'isRunningInConsole');
    $console->setValue($this->app, false);

    try {
        Live::forget();
        Live::touch($brand->getKey());
        $first = Live::stamps($brand->getKey(), $user->getKey())['b'];

        // The job touches the brand again within the same second: that change used to wait for a flush that had already run.
        RunSoon::dispatch(new TouchBrandAgain((int) $brand->getKey()));
        $this->app->terminate();

        expect(Live::stamps($brand->getKey(), $user->getKey())['b'])->toBeGreaterThan($first);
    } finally {
        $console->setValue($this->app, true);
    }
});

it('gives the menu\'s counts as they are now, for the menu the person has', function (): void {
    $brand = brand(['slug' => 'ukph']);
    inBrand($brand);
    $department = department($brand);
    $manager = managerOf($department);
    Lead::factory()->count(3)->create(['brand_id' => $brand->getKey(), 'department_id' => $department->getKey()]);

    $this->actingAs($manager)->getJson('/b/ukph/menu-badges')
        ->assertOk()
        ->assertJson([route('brand.leads.index', ['brand' => 'ukph']) => '3', route('brand.dashboard', ['brand' => 'ukph']) => null]);

    $this->actingAs($manager)->get('/b/ukph/leads')->assertSee('data-menu-badges="'.route('brand.menu-badges', ['brand' => 'ukph']).'"', false)->assertSee('data-badge-for=', false);

    // Somebody of another brand gets nothing of this one.
    $stranger = managerOf(department(brand(['slug' => 'anz'])));
    $this->actingAs($stranger)->getJson('/b/ukph/menu-badges')->assertNotFound();

    // Outside a brand an Admin gets the admin menu's.
    app(CurrentBrand::class)->clear();
    $this->actingAs(admin())->getJson('/menu-badges')->assertOk()->assertJsonStructure([route('admin.dashboard')]);
});

it('shows a brand in its second colour when the main one says nothing', function (): void {
    expect((new Brand(['colors' => ['primary' => '#FFFFFF', 'secondary' => '#1D4ED8']]))->primaryColor())->toBe('#1D4ED8')
        ->and((new Brand(['colors' => ['primary' => '#1D4ED8', 'secondary' => '#DC2626']]))->primaryColor())->toBe('#1D4ED8')
        ->and((new Brand(['colors' => ['primary' => '#FEFEFE']]))->primaryColor())->toBeNull()
        ->and((new Brand(['colors' => null]))->primaryColor())->toBeNull()
        ->and(Brand::colorSaysSomething('#0A0A0A'))->toBeFalse()
        ->and(Brand::colorSaysSomething('#FF6B2C'))->toBeTrue();
});
