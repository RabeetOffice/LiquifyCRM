<?php

declare(strict_types=1);

use App\Modules\Brands\Livewire\App\EmailSettings;
use App\Modules\Brands\Support\BrandMailer;
use App\Modules\Brands\Support\MailProviders;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Enums\SiteMode;
use App\Modules\Sites\Enums\SitePlatform;
use App\Modules\Sites\Livewire\App\SiteShow;
use App\Modules\Sites\Livewire\App\SiteWizard;
use App\Modules\Sites\Models\InboundEvent;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Week 3 screens: Websites list, the 3-step Connect website wizard, the
 * single-website page and the brand's email settings.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->other = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin();

    inBrand($this->brand);
});

it('shows Websites to Admins and Managers, never to Agents or strangers', function (): void {
    $site = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH main site']);
    Site::factory()->create(['brand_id' => $this->other->getKey(), 'name' => 'ANZ main site']);

    $this->actingAs($this->admin)->get('/b/ukph/sites')
        ->assertOk()
        ->assertSee('UKPH main site')
        ->assertSee('Connect website')
        ->assertSee('Waiting for the first lead')
        ->assertDontSee('ANZ main site');

    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/ukph/sites')->assertOk()->assertSee('UKPH main site')->assertDontSee('Connect website');
    $this->actingAs($this->manager)->get('/b/ukph/sites/connect')->assertForbidden();
    $this->actingAs($this->manager)->get('/b/ukph/sites/'.$site->getKey().'/download')->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/ukph/sites')->assertForbidden();

    $this->flushSession();
    $stranger = managerOf(department($this->other));
    inBrand($this->brand);
    $this->actingAs($stranger)->get('/b/ukph/sites')->assertNotFound();
});

it('does not open a website of another brand', function (): void {
    $foreign = Site::factory()->create(['brand_id' => $this->other->getKey()]);

    $this->actingAs($this->admin)->get('/b/ukph/sites/'.$foreign->getKey())->assertNotFound();
    $this->actingAs($this->admin)->get('/b/ukph/sites/'.$foreign->getKey().'/download')->assertNotFound();
});

it('connects a PHP website in three steps', function (): void {
    Http::fake(['*' => Http::response('<html></html>', 200)]);

    Livewire::actingAs($this->admin)
        ->test(SiteWizard::class, ['brand' => $this->brand])
        ->set('name', 'UKPH')
        ->set('url', 'ukpublishinghouse.co.uk/')
        ->set('connection', 'php')
        ->call('createSite')
        ->assertHasNoErrors()
        ->assertRedirect();

    $site = Site::query()->sole();

    expect($site->url)->toBe('https://ukpublishinghouse.co.uk')
        ->and($site->domain)->toBe('ukpublishinghouse.co.uk')
        ->and($site->mode)->toBe(SiteMode::Push)
        ->and($site->platform)->toBe(SitePlatform::Php)
        ->and($site->site_key)->toStartWith('ukpublishi_')
        ->and(strlen($site->secret))->toBe(48)
        ->and($site->getRawOriginal('secret'))->not->toContain($site->secret);

    // Step 2: the download carries the address and the secret; the screen never shows the secret.
    $download = $this->actingAs($this->admin)->get('/b/ukph/sites/'.$site->getKey().'/download')->assertOk();

    expect($download->headers->get('Content-Disposition'))->toContain('liquify.php')
        ->and($download->getContent())->toContain("const LIQUIFY_URL    = '".$site->ingestUrl()."';")
        ->and($download->getContent())->toContain("const LIQUIFY_SECRET = '".$site->secret."';")
        ->and($download->getContent())->toContain('function liquify_send(');

    // Step 3: Test connection sends a signed test through the pipeline without creating a lead.
    Livewire::actingAs($this->admin)
        ->test(SiteWizard::class, ['brand' => $this->brand, 'site' => $site])
        ->assertSet('step', 2)
        ->assertSee('liquify_send(')
        ->assertDontSee($site->secret)
        ->call('goTo', 3)
        ->call('test')
        ->assertSee('A signed test passed')
        ->assertSee('includes/liquify.php is on the website');

    expect(InboundEvent::query()->sole()->status)->toBe(InboundStatus::Done)
        ->and($site->refresh()->last_test_at)->not->toBeNull()
        ->and($site->last_lead_at)->toBeNull();
});

it('gives an Elementor website a webhook address instead of a file', function (): void {
    Livewire::actingAs($this->admin)
        ->test(SiteWizard::class, ['brand' => $this->brand])
        ->set('name', 'Blog')
        ->set('url', 'https://blog.example.com')
        ->set('connection', 'elementor')
        ->call('createSite');

    $site = Site::query()->sole();

    Livewire::actingAs($this->admin)
        ->test(SiteWizard::class, ['brand' => $this->brand, 'site' => $site])
        ->assertSee($site->webhookUrl())
        ->assertDontSee('Download liquify.php');
});

it('retries a failed lead from the website page', function (): void {
    $site = Site::factory()->create(['brand_id' => $this->brand->getKey()])->refresh();
    $event = InboundEvent::query()->create([
        'source' => 'site', 'source_id' => $site->getKey(), 'brand_id' => $this->brand->getKey(), 'external_id' => '7',
        'payload' => ['form' => 'contact', 'fields' => ['name' => 'Sarah Lee', 'email' => 'sarah@example.com']],
        'status' => 'failed', 'error' => 'Database was offline',
    ]);

    Livewire::actingAs($this->admin)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $site])
        ->set('tab', 'leads')
        ->assertSee('Database was offline')
        ->call('retry', $event->getKey());

    expect($event->refresh()->status)->toBe(InboundStatus::Done)
        ->and($event->lead_id)->not->toBeNull();

    Livewire::actingAs($this->manager)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $site])
        ->call('retry', $event->getKey())
        ->assertForbidden();
});

it('creates a new secret only after the password is confirmed', function (): void {
    $site = Site::factory()->create(['brand_id' => $this->brand->getKey()])->refresh();
    $old = $site->secret;

    Livewire::actingAs($this->admin)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $site])
        ->call('confirmNewSecret')
        ->set('confirmPassword', 'wrong-password')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword');

    expect($site->refresh()->secret)->toBe($old);

    Livewire::actingAs($this->admin)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $site])
        ->call('confirmNewSecret')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect($site->refresh()->secret)->not->toBe($old);
});

it('stores the brand email account encrypted and sends a test through it', function (): void {
    $mailer = Mockery::mock(BrandMailer::class);
    $mailer->shouldReceive('send')->once()->withArgs(fn ($brand, string $to): bool => $brand->is($this->brand) && $to === $this->admin->email);
    app()->instance(BrandMailer::class, $mailer);

    Livewire::actingAs($this->admin)
        ->test(EmailSettings::class, ['brand' => $this->brand])
        ->set('host', 'smtp.gmail.com')
        ->set('username', 'hello@ukph.test')
        ->set('password', 'abcd efgh ijkl mnop')
        ->set('fromAddress', 'hello@ukph.test')
        ->set('autoReply', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Gmail')
        ->call('sendTest');

    $brand = $this->brand->refresh();

    expect($brand->autoReplyEnabled())->toBeTrue()
        ->and($brand->mailSettings()['password'])->toBe('abcd efgh ijkl mnop')
        ->and($brand->getRawOriginal('mail_settings'))->not->toContain('abcd efgh');

    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/ukph/settings/email')->assertForbidden();
});

it('fills in the server details from a ready-made email provider', function (): void {
    $this->brand->update(['website' => 'https://www.ukpublishinghouse.co.uk']);

    Livewire::actingAs($this->admin)
        ->test(EmailSettings::class, ['brand' => $this->brand])
        ->assertSee('Who provides your email?')
        ->assertSee('Microsoft 365 / Exchange')
        ->assertSee('mail.ukpublishinghouse.co.uk')
        // Nothing to fill in until a provider is chosen.
        ->assertDontSee('Server details')
        ->call('usePreset', 'google')
        ->assertSet('host', 'smtp.gmail.com')
        ->assertSet('port', '587')
        ->assertSet('encryption', 'tls')
        ->assertSee('Server details')
        ->assertSee('16-character app password')
        // SSL and TLS keep their usual ports together.
        ->set('encryption', 'ssl')
        ->assertSet('port', '465')
        ->call('usePreset', 'hostinger')
        ->assertSet('host', 'smtp.hostinger.com')
        ->assertSet('port', '465')
        ->assertSet('encryption', 'ssl')
        ->call('usePreset', 'webmail')
        ->assertSet('host', 'mail.ukpublishinghouse.co.uk')
        // A service with a fixed username fills it in; a username already typed is never replaced.
        ->call('usePreset', 'sendgrid')
        ->assertSet('username', 'apikey')
        ->set('username', 'hello@ukph.test')
        ->call('usePreset', 'zoho')
        ->assertSet('username', 'hello@ukph.test')
        ->assertSet('host', 'smtp.zoho.com')
        // Typing a server by hand moves the picker; "Another provider" keeps what is entered.
        ->set('host', 'smtp.office365.com')
        ->assertSet('preset', 'microsoft')
        ->call('usePreset', 'other')
        ->assertSet('host', 'smtp.office365.com')
        ->call('usePreset', 'not-a-provider')
        ->assertSet('preset', 'other');

    expect(MailProviders::detect('mail.privateemail.com'))->toBe('namecheap')
        ->and(MailProviders::detect('smtp.mail.me.com'))->toBe('icloud')
        ->and(MailProviders::detect('mail.example.org'))->toBe('webmail')
        ->and(MailProviders::detect('relay.example.org'))->toBe('other')
        ->and(MailProviders::detect(''))->toBeNull()
        ->and(MailProviders::webmailHost(null))->toBe('');
});

it('shows the website favicon and name as the source of its leads, and nothing of a Facebook Page with the same id', function (): void {
    $site = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH main site', 'favicon_path' => 'sites/ukph.png']);

    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Wendy Website', 'source_type' => LeadSource::Website, 'source_id' => $site->getKey()]);
    $facebook = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Fiona Facebook', 'source_type' => LeadSource::Facebook, 'source_id' => $site->getKey()]);

    $this->actingAs($this->admin)->get('/b/ukph/leads')
        ->assertOk()
        ->assertSee('UKPH main site')
        ->assertSee('sites/ukph.png');

    $this->get('/b/ukph')->assertOk()->assertSee('sites/ukph.png');
    $this->get('/b/ukph/leads/pipeline')->assertOk()->assertSee('sites/ukph.png');
    $this->get('/b/ukph/leads/'.$facebook->getKey())->assertOk()->assertDontSee('UKPH main site');
});

it('names the website by itself, and keeps a name somebody typed', function (): void {
    $this->brand->forceFill(['website' => 'https://ukpublishinghouse.co.uk'])->save();

    Http::fake([
        'blog.example.com/*' => Http::response('<html><head><meta property="og:site_name" content="The Writers Blog"><title>Home</title></head></html>', 200),
        '*' => Http::response('', 404),
    ]);

    Livewire::actingAs($this->admin)
        ->test(SiteWizard::class, ['brand' => $this->brand->fresh()])
        ->assertSet('url', 'https://ukpublishinghouse.co.uk')
        ->assertSet('name', 'UK Publishing House')
        ->set('url', 'blog.example.com')
        ->assertSet('name', 'The Writers Blog')
        ->set('url', 'https://www.unreadable.example.org/')
        ->assertSet('name', 'unreadable.example.org')
        ->set('name', 'My own name')
        ->set('url', 'https://ukpublishinghouse.co.uk')
        ->assertSet('name', 'My own name');
});
