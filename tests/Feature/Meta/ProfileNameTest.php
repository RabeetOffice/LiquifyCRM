<?php

declare(strict_types=1);

use App\Modules\Core\Livewire\GlobalSearch;
use App\Modules\Leads\Actions\UpdateLeadDetails;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadEnquiry;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The name on the person's Facebook profile (user report, 27 Oct 2026): "Lead
 * name on the CRM is picked from the form the client fills out. Messenger
 * can't search in chat, it can only search profile names." The lead keeps the
 * typed name and shows, copies and is found by the profile name too.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret', 'liquify.meta.verify_token' => 'verify-me']);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();
});

/** @param  array<string, mixed>  $payload */
function signedMetaPost(array $payload)
{
    $body = (string) json_encode($payload);

    return test()->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body);
}

/** A lead form where the person typed "Jo", whose Facebook profile says Johnny Walker. */
function fakeFormOfJo(string $psid = '7773000400050006'): void
{
    Http::fake([
        'graph.facebook.com/*/9101*' => Http::response(['id' => '9101', 'form_id' => '555', 'field_data' => [
            ['name' => 'full_name', 'values' => ['Jo']],
            ['name' => 'email', 'values' => ['jo@example.com']],
            ['name' => 'inbox_url', 'values' => ['https://business.facebook.com/latest/'.$psid.'?nav_ref=thread_view_by_psid']],
        ]]),
        'graph.facebook.com/*/555*' => Http::response(['name' => 'Free consultation']),
        'graph.facebook.com/*/'.$psid.'*' => Http::response(['first_name' => 'Johnny', 'last_name' => 'Walker']),
    ]);
}

it('keeps the Facebook name of a lead form beside the typed one, and finds the lead by it', function (): void {
    fakeFormOfJo();

    signedMetaPost(['object' => 'page', 'entry' => [['id' => '111', 'time' => time(), 'changes' => [[
        'field' => 'leadgen',
        'value' => ['leadgen_id' => '9101', 'page_id' => '111', 'form_id' => '555', 'created_time' => time()],
    ]]]]])->assertOk();

    inBrand($this->brand);
    $lead = Lead::query()->sole();
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Somebody Else']);

    expect($lead->name)->toBe('Jo')
        ->and($lead->profile_name)->toBe('Johnny Walker')
        ->and(LeadEnquiry::copyText($lead, true))->toContain("Jo\nFacebook name: Johnny Walker");

    // Only the name was wanted: no separate request for the picture.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/picture'));

    Livewire::actingAs(admin())
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('On Facebook:')
        ->assertSee('Johnny Walker');

    Livewire::actingAs(admin())
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('search', 'johnny')
        ->assertSee('Johnny Walker')
        ->assertDontSee('Somebody Else');

    Livewire::actingAs(admin())
        ->test(GlobalSearch::class)
        ->set('open', true)
        ->set('query', 'Walker')
        ->assertSee('Facebook: Johnny Walker');
});

it('keeps the Messenger name when the team gives the lead another one', function (): void {
    Http::fake(['graph.facebook.com/*/777*' => Http::response(['first_name' => 'Amy', 'last_name' => 'Wong'])]);

    signedMetaPost(['object' => 'page', 'entry' => [['id' => '111', 'time' => time(), 'messaging' => [[
        'sender' => ['id' => '777'],
        'recipient' => ['id' => '111'],
        'timestamp' => time() * 1000,
        'message' => ['mid' => 'mid.1', 'text' => 'Hi'],
    ]]]]])->assertOk();

    inBrand($this->brand);
    $lead = Lead::query()->sole();

    // The same name twice says nothing.
    expect($lead->profile_name)->toBe('Amy Wong');
    Livewire::actingAs(admin())->test(LeadPanel::class, ['leadId' => $lead->getKey()])->assertDontSee('On Facebook:');

    app(UpdateLeadDetails::class)->handle($lead, ['name' => 'Amy (children\'s book)'], admin());

    Livewire::actingAs(admin())
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('On Facebook:')
        ->assertSee('Amy Wong');
});

it('learns the name of leads that came before, a few at a time and not again for twelve hours', function (): void {
    Http::fake(['graph.facebook.com/*/7774000500060007*' => Http::response(['first_name' => 'Bilal', 'last_name' => 'Khan'])]);
    inBrand($this->brand);

    $lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'B', 'source_type' => LeadSource::Facebook, 'source_id' => $this->page->getKey(),
        'fields' => ['inbox_url' => 'https://business.facebook.com/latest/7774000500060007?nav_ref=thread_view_by_psid'],
    ]);
    $website = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'From the website', 'source_type' => LeadSource::Website]);

    $this->artisan('meta:profile-names')->assertSuccessful();

    expect($lead->refresh()->profile_name)->toBe('Bilal Khan')
        ->and($website->refresh()->profile_name)->toBeNull();

    $lead->forceFill(['profile_name' => null])->save();
    $this->artisan('meta:profile-names')->assertSuccessful();

    expect($lead->refresh()->profile_name)->toBeNull();
    Http::assertSentCount(1);
});

it('shows nothing when Meta shares no name', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported get request.', 'code' => 100]], 400)]);
    inBrand($this->brand);

    $lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'C', 'source_type' => LeadSource::Facebook, 'source_id' => $this->page->getKey(),
        'fields' => ['inbox_url' => 'https://business.facebook.com/latest/7775000600070008?nav_ref=thread_view_by_psid'],
    ]);

    Livewire::actingAs(admin())
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertOk()
        ->assertDontSee('On Facebook:');

    expect($lead->refresh()->profile_name)->toBeNull();
});
