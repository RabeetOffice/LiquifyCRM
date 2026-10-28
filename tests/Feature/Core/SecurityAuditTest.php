<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Support\BrandIdentity;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Support\SafeSvg;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\WebsiteLeadsBoard;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\WebsiteLeads;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Security audit, 28 Oct 2026 (Cloudflare security-audit skill, run 1).
 * 1. Livewire updates must re-check the admin and brand access middleware,
 *    so a suspended person cannot keep working from a screen already open.
 * 2. and 3. A picture fetched from an outside website is served from our own
 *    address, so an SVG must be a drawing and nothing more.
 */

/** The snapshot of the first Livewire component on the page whose data has the given property. */
function snapshotWith(string $html, string $property): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $found);

    foreach ($found[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if (array_key_exists($property, json_decode($snapshot, true)['data'] ?? [])) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No component with {$property} on the page.");
}

/** Sends a Livewire update to the real endpoint, as the browser does. */
function livewireUpdate(string $snapshot, array $updates, string $method): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['method' => $method, 'params' => [], 'metadata' => []]],
        ]],
    ]);
}

it('does not let a suspended Admin create an Admin from a screen opened before the suspension', function (): void {
    $admin = admin();
    $this->actingAs($admin);

    $snapshot = snapshotWith($this->get('/admin/people/create')->assertOk()->getContent(), 'kind');

    $admin->forceFill(['status' => MembershipStatus::Suspended])->save();

    $this->get('/admin/people/create')->assertForbidden();

    livewireUpdate($snapshot, [
        'name' => 'Probe', 'email' => 'probe@example.test', 'password' => 'Abcdefgh12345', 'kind' => 'admin',
    ], 'save')->assertForbidden();

    expect(User::query()->where('email', 'probe@example.test')->exists())->toBeFalse();
});

it('still lets an active Admin save from the same screen', function (): void {
    $this->actingAs(admin());

    $snapshot = snapshotWith($this->get('/admin/people/create')->assertOk()->getContent(), 'kind');

    livewireUpdate($snapshot, [
        'name' => 'Probe', 'email' => 'probe@example.test', 'password' => 'Abcdefgh12345', 'kind' => 'admin',
    ], 'save')->assertOk();

    expect(User::query()->where('email', 'probe@example.test')->value('role')?->value)->toBe('admin');
});

it('does not let a suspended Manager save targets from a screen opened before the suspension', function (): void {
    $brand = brand();
    $team = department($brand);
    $manager = managerOf($team);
    $agent = agentOf($team);
    $this->actingAs($manager);

    $snapshot = snapshotWith($this->get('/b/'.$brand->slug.'/targets')->assertOk()->getContent(), 'values');

    $manager->membershipFor($brand)->forceFill(['status' => MembershipStatus::Suspended])->save();

    $this->get('/b/'.$brand->slug.'/targets')->assertForbidden();

    livewireUpdate($snapshot, ['values.user:'.$agent->getKey().'.revenue' => '999'], 'save')->assertForbidden();
});

it('refuses SVGs that could run a script, however they are written', function (string $svg): void {
    expect(SafeSvg::isDrawing($svg))->toBeFalse();
})->with([
    'script' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'prefixed script' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="http://www.w3.org/2000/svg"><x:script>alert(1)</x:script></svg>',
    'xhtml script' => '<svg xmlns="http://www.w3.org/2000/svg"><h:script xmlns:h="http://www.w3.org/1999/xhtml">alert(1)</h:script></svg>',
    'event attribute' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
    'encoded link' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript&#58;alert(1)"><rect width="9" height="9"/></a></svg>',
    'animated link' => '<svg xmlns="http://www.w3.org/2000/svg"><a href="#"><set attributeName="href" to="javascript:alert(1)"/><rect width="9" height="9"/></a></svg>',
    'foreign object' => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>',
    'entities' => '<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
    'stylesheet' => '<?xml-stylesheet type="text/xsl" href="https://evil.test/x.xsl"?><svg xmlns="http://www.w3.org/2000/svg"/>',
    'outside picture' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.test/a.svg"/></svg>',
    'not svg' => '<html><body>hi</body></html>',
    'broken' => '<svg xmlns="http://www.w3.org/2000/svg"><g>',
]);

it('keeps ordinary SVG logos', function (string $svg): void {
    expect(SafeSvg::isDrawing($svg))->toBeTrue();
})->with([
    'shapes' => '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>Logo</title><g fill="#B91C1C"><rect width="10" height="10" rx="2"/><path d="M1 1h8v8z"/></g></svg>',
    'gradients and reuse' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient><circle id="c" r="4"/></defs><use xlink:href="#c" fill="url(#g)"/><style>.a{fill:red}</style></svg>',
    'editor metadata' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"><sodipodi:namedview id="v"/><metadata/><text x="1" y="9">Brand</text></svg>',
    'embedded picture' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,iVBORw0KGgo=" width="9" height="9"/></svg>',
]);

it('never stores a website favicon SVG that could run a script', function (): void {
    Storage::fake('public');
    Http::fake([
        'icons.test/i.svg' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>', 200, ['Content-Type' => 'image/svg+xml']),
        'icons.test/favicon.ico' => Http::response('', 404),
        'icons.test/*' => Http::response('<html><head><link rel="icon" href="/i.svg"></head></html>'),
    ]);

    $site = Site::factory()->create(['brand_id' => brand()->getKey(), 'url' => 'https://icons.test', 'favicon_path' => null]);

    app(SiteIdentity::class)->refresh($site, Http::get('https://icons.test/')->body());

    expect($site->refresh()->favicon_path)->toBeNull();
    Storage::disk('public')->assertMissing('sites/favicons/'.$site->getKey().'.svg');
});

it('never stores a brand favicon SVG with a prefixed script element', function (): void {
    Storage::fake('public');
    Http::fake([
        'prefixed.test/i.svg' => Http::response('<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="http://www.w3.org/2000/svg"><x:script>alert(1)</x:script></svg>', 200, ['Content-Type' => 'image/svg+xml']),
        'prefixed.test/*' => Http::response('<html><head><link rel="icon" sizes="512x512" href="/i.svg"></head></html>'),
    ]);

    $brand = brand(['website' => 'https://prefixed.test', 'favicon_path' => null, 'logo_path' => null]);

    app(BrandIdentity::class)->fetch($brand, false, false, true);

    expect($brand->refresh()->favicon_path)->toBeNull();
    Storage::disk('public')->assertMissing('brands/favicons/fetched-'.$brand->getKey().'.svg');
});

it('still stores a plain SVG favicon from a website', function (): void {
    Storage::fake('public');
    Http::fake([
        'plain.test/i.svg' => Http::response('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9 9"><rect width="9" height="9" fill="#0EA5E9"/></svg>', 200, ['Content-Type' => 'image/svg+xml']),
        'plain.test/*' => Http::response('<html><head><link rel="icon" href="/i.svg"></head></html>'),
    ]);

    $site = Site::factory()->create(['brand_id' => brand()->getKey(), 'url' => 'https://plain.test', 'favicon_path' => null]);

    app(SiteIdentity::class)->refresh($site, Http::get('https://plain.test/')->body());

    expect($site->refresh()->favicon_path)->toBe('sites/favicons/'.$site->getKey().'.svg');
});

/** A session row in the database store, as a signed-in browser leaves it. */
function sessionRowFor(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->getKey(), 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);
}

it('signs a person out everywhere whenever their password is replaced', function (): void {
    config(['session.driver' => 'database']);
    $agent = User::factory()->create();
    sessionRowFor($agent, 'old-browser');
    $token = $agent->getRememberToken();

    // An Admin on People › Edit, the emailed reset and the authenticator reset all end up here.
    $agent->forceFill(['password' => 'NewPassw0rd99'])->save();

    expect(DB::table('sessions')->where('user_id', $agent->getKey())->count())->toBe(0)
        ->and($agent->fresh()->getRememberToken())->not->toBe($token);
});

it('keeps the browser that changed its own password signed in', function (): void {
    config(['session.driver' => 'database']);
    $agent = User::factory()->create();
    $this->actingAs($agent);
    $current = app('session.store')->getId();
    sessionRowFor($agent, $current);
    sessionRowFor($agent, 'other-browser');

    $agent->forceFill(['password' => 'NewPassw0rd99'])->save();

    expect(DB::table('sessions')->where('user_id', $agent->getKey())->pluck('id')->all())->toBe([$current]);
});

it('does not let a Website leads person uncover a masked contact by searching parts of it', function (): void {
    $brand = brand();
    $web = User::factory()->create(['role' => GlobalRole::WebsiteLeads])->refresh();
    WebsiteLeads::choose($web, [$brand->getKey()]);
    Lead::factory()->create([
        'brand_id' => $brand->getKey(), 'source_type' => LeadSource::Website,
        'name' => 'Target Tina', 'email' => 'secretlocal@example.com', 'phone' => '07700900123',
    ]);

    $board = Livewire\Livewire::actingAs($web)->test(WebsiteLeadsBoard::class);

    foreach (['secretl', 's_c%@example.com', '0090', '%'] as $probe) {
        $board->set('search', $probe)->assertDontSee('Target Tina');
    }

    // The whole address, or the name, still finds the lead.
    $board->set('search', 'secretlocal@example.com')->assertSee('Target Tina')
        ->set('search', 'Tina')->assertSee('Target Tina');

    // An Admin sees contacts in full, so parts of them match.
    Livewire\Livewire::actingAs(admin())->test(WebsiteLeadsBoard::class)
        ->set('search', 'secretl')->assertSee('Target Tina');
});

it('does not show a colleague\'s sale money on the lead timeline', function (): void {
    $brand = brand(['currency' => 'GBP']);
    $front = department($brand, ['name' => 'Front Sales']);
    $ali = agentOf($front, ['name' => 'Ali Khan']);
    $sara = agentOf($front, ['name' => 'Sara Malik']);
    inBrand($brand);
    $lead = Lead::factory()->create(['brand_id' => $brand->getKey(), 'department_id' => $front->getKey(), 'assigned_to' => $sara->getKey()]);
    app(RecordSale::class)->handle($lead, 4321, 'Publishing', now(), $sara);

    Livewire\Livewire::actingAs($ali)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->set('tab', 'timeline')
        ->assertDontSee('4,321')
        ->assertDontSee('Sale recorded');

    // The seller still sees their own sale there.
    Livewire\Livewire::actingAs($sara)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->set('tab', 'timeline')
        ->assertSee('Sale recorded');
});
