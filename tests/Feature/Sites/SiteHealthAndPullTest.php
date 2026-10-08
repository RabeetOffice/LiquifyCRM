<?php

declare(strict_types=1);

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Enums\CheckStatus;
use App\Modules\Sites\Enums\SitePlatform;
use App\Modules\Sites\Jobs\PullSiteLeads;
use App\Modules\Sites\Jobs\RunSiteChecks;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteCheck;
use App\Modules\Sites\Support\CertificateReader;
use App\Modules\Sites\Support\SiteDatabase;
use App\Modules\Sites\Support\SiteHealthChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Week 3: per-site health checks ported from LeadsHub (form, email, database,
 * SSL, uptime), the 48-hour quiet warning, and pull mode.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->site = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH', 'url' => 'https://ukph.test'])->refresh();

    $this->certificate = function (array $result): void {
        $reader = Mockery::mock(CertificateReader::class);
        $reader->shouldReceive('read')->andReturn([...['ok' => true, 'days' => 80, 'expires' => '1 Jan 2027', 'issuer' => "Let's Encrypt", 'error' => ''], ...$result]);
        app()->instance(CertificateReader::class, $reader);
    };
});

/** @return array<string, SiteCheck> */
function latestChecks(Site $site): array
{
    return SiteCheck::latestFor([$site->getKey()])[$site->getKey()] ?? [];
}

it('marks a healthy website green and reads its favicon and platform', function (): void {
    ($this->certificate)([]);

    Http::fake([
        'ukph.test/favicon-180.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        'ukph.test/form-submission.php' => Http::response('', 405),
        'ukph.test/*' => Http::response('<html><head><link rel="apple-touch-icon" sizes="180x180" href="/favicon-180.png"><link rel="stylesheet" href="/wp-content/themes/x/style.css"></head></html>', 200),
    ]);

    $status = app(SiteHealthChecker::class)->run($this->site);
    $checks = latestChecks($this->site);

    expect($status)->toBe(CheckStatus::Ok)
        ->and($checks['uptime']->status)->toBe(CheckStatus::Ok)
        ->and($checks['ssl']->status)->toBe(CheckStatus::Ok)
        ->and($checks['form']->status)->toBe(CheckStatus::Ok)
        ->and($checks['smtp']->status)->toBe(CheckStatus::Skip)
        ->and($checks['database']->status)->toBe(CheckStatus::Skip)
        ->and($this->site->refresh()->health_status)->toBe(CheckStatus::Ok)
        ->and($this->site->platform)->toBe(SitePlatform::WordPress)
        ->and($this->site->favicon_path)->toBe('sites/favicons/'.$this->site->getKey().'.png');

    Storage::disk('public')->assertExists($this->site->favicon_path);
});

it('turns red when the website is down, the form is missing or the certificate expired', function (): void {
    ($this->certificate)(['days' => -3, 'expires' => '5 Oct 2026']);

    Http::fake([
        'ukph.test/form-submission.php' => Http::response('', 404),
        'ukph.test/*' => Http::response('', 503),
    ]);

    expect(app(SiteHealthChecker::class)->run($this->site))->toBe(CheckStatus::Fail);

    $checks = latestChecks($this->site);

    expect($checks['uptime']->status)->toBe(CheckStatus::Fail)
        ->and($checks['ssl']->status)->toBe(CheckStatus::Fail)
        ->and($checks['form']->status)->toBe(CheckStatus::Fail)
        ->and($checks['form']->message)->toContain('/form-submission.php');
});

it('turns amber and emails the Admins once when a website is quiet for 48 hours', function (): void {
    ($this->certificate)([]);
    Http::fake(['*' => Http::response('<html></html>', 200)]);

    $admin = admin();
    $this->site->update(['last_lead_at' => now()->subHours(50)]);

    expect(app(SiteHealthChecker::class)->run($this->site))->toBe(CheckStatus::Warn)
        ->and(latestChecks($this->site)['form']->status)->toBe(CheckStatus::Warn)
        ->and($this->site->refresh()->connectionState())->toBe('quiet');

    app(SiteHealthChecker::class)->run($this->site->refresh());

    Notification::assertSentToTimes($admin, AppNotification::class, 1);
    Notification::assertSentTo($admin, fn (AppNotification $notification): bool => str_contains($notification->title, 'quiet') && $notification->mail);
});

it('reads the email and database checks from the health agent', function (): void {
    ($this->certificate)([]);

    $this->site->update(['pull_config' => ['driver' => 'agent', 'agent' => ['url' => '', 'key' => 'key-1', 'secret' => 'agent-secret']]]);

    Http::fake([
        'ukph.test/hub-agent.php' => function (Request $request) {
            $expected = hash_hmac('sha256', $request->header('X-Hub-Timestamp')[0]."\n".$request->header('X-Hub-Nonce')[0]."\n".$request->body(), 'agent-secret');

            expect($request->header('X-Hub-Signature')[0])->toBe($expected)
                ->and($request->header('X-Hub-Key')[0])->toBe('key-1');

            return Http::response(['ok' => true, 'agent' => '1.0', 'data' => ['checks' => [
                ['group' => 'database', 'key' => 'db_login', 'label' => 'MySQL login', 'status' => 'ok', 'message' => 'Connected', 'ms' => 12],
                ['group' => 'email', 'key' => 'smtp_connect', 'label' => 'SMTP connect', 'status' => 'ok', 'message' => 'Connected', 'ms' => 40],
                ['group' => 'email', 'key' => 'smtp_auth', 'label' => 'SMTP login', 'status' => 'fail', 'message' => 'Username and password not accepted', 'fix' => 'Create a new Gmail app password.', 'ms' => 90],
            ]]]);
        },
        'ukph.test/*' => Http::response('<html></html>', 200),
    ]);

    expect(app(SiteHealthChecker::class)->run($this->site->refresh()))->toBe(CheckStatus::Fail);

    $checks = latestChecks($this->site);

    expect($checks['database']->status)->toBe(CheckStatus::Ok)
        ->and($checks['smtp']->status)->toBe(CheckStatus::Fail)
        ->and($checks['smtp']->message)->toContain('SMTP login')->toContain('Create a new Gmail app password.');
});

it('queues checks from the admin route and the scheduler command', function (): void {
    Queue::fake();

    $this->actingAs(admin())->postJson('/admin/sites/'.$this->site->getKey().'/check')->assertStatus(202);
    $this->artisan('sites:check')->assertSuccessful();

    Queue::assertPushed(RunSiteChecks::class, 2);

    $this->flushSession();
    $this->actingAs(managerOf(department($this->brand)))->postJson('/admin/sites/'.$this->site->getKey().'/check')->assertForbidden();
});

it('reads only new rows of a pull-mode website through the agent', function (): void {
    $this->site->update(['mode' => 'pull', 'pull_config' => ['driver' => 'agent', 'agent' => ['url' => 'https://ukph.test/hub-agent.php', 'key' => 'key-1', 'secret' => 'agent-secret']]]);

    $rows = [
        ['id' => 40, 'full_name' => 'Old Lead', 'email' => 'old@example.com', 'created_at' => '2026-09-01 10:00:00'],
        ['id' => 41, 'full_name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'telephone' => '07700 900123', 'form_type' => 'popup', 'msg' => 'Hello', 'created_at' => now()->subMinutes(3)->toDateTimeString()],
    ];

    Http::fake(['ukph.test/hub-agent.php' => function (Request $request) use (&$rows) {
        $since = (int) ($request->data()['params']['since_id'] ?? 0);

        return Http::response(['ok' => true, 'data' => ['leads' => array_values(array_filter($rows, fn (array $row): bool => $row['id'] > $since)), 'has_more' => false, 'note' => '']]);
    }]);

    // First run: only note where the table ends.
    $first = [$rows[0]];
    [$all, $rows] = [$rows, $first];
    PullSiteLeads::dispatchSync($this->site->getKey());

    expect($this->site->refresh()->pull_cursor)->toBe(40)
        ->and(Lead::query()->acrossBrands()->count())->toBe(0);

    // Next run: the new row becomes a lead, once.
    $rows = $all;
    PullSiteLeads::dispatchSync($this->site->getKey());
    PullSiteLeads::dispatchSync($this->site->getKey());

    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect($lead->name)->toBe('Sarah Lee')
        ->and($lead->phone)->toBe('+447700900123')
        ->and($lead->message)->toBe('Hello')
        ->and($lead->external_id)->toBe($this->site->getKey().':41')
        ->and($this->site->refresh()->pull_cursor)->toBe(41)
        ->and($this->site->last_pull_note)->toBe('No new leads.');
});

it('reads a pull-mode website straight from its leads table', function (): void {
    $this->site->update(['mode' => 'pull', 'pull_cursor' => 10, 'pull_config' => ['driver' => 'database', 'database' => ['host' => 'db.ukph.test', 'database' => 'ukph', 'username' => 'reader', 'password' => 'secret', 'table' => 'leads']]]);

    $database = Mockery::mock(SiteDatabase::class);
    $database->shouldReceive('rows')->once()->withArgs(fn (array $config, int $after): bool => $config['table'] === 'leads' && $after === 10)
        ->andReturn([['id' => 11, 'name' => 'Tom Hall', 'email' => 'tom@example.com', 'ip_address' => '1.2.3.4']]);
    app()->instance(SiteDatabase::class, $database);

    $this->artisan('sites:pull')->assertSuccessful();

    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect($lead->name)->toBe('Tom Hall')
        ->and($lead->fields)->not->toHaveKey('ip_address')
        ->and($this->site->refresh()->pull_cursor)->toBe(11)
        ->and($this->site->last_pull_note)->toBe('1 new lead read.');
});
