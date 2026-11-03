<?php

declare(strict_types=1);

use App\Modules\Sites\Enums\CheckStatus;
use App\Modules\Sites\Enums\SiteMode;
use App\Modules\Sites\Enums\SitePlatform;
use App\Modules\Sites\Livewire\App\SiteShow;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteCheck;
use App\Modules\Sites\Support\CertificateReader;
use App\Modules\Sites\Support\LiquifySnippet;
use App\Modules\Sites\Support\SiteHealthChecker;
use App\Modules\Sites\Support\SiteIdentity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * What kind of website a site is (user request, 3 Nov 2026): every brand site
 * is custom PHP, one old WordPress picture made UK Publishing House
 * "WordPress", and a PHP website that sent each lead itself lost a lead whose
 * send failed. A chosen type sticks, and a custom PHP website is read every
 * minute through liquify.php as soon as the file can read its leads table.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->admin = admin();
    $this->site = Site::factory()->create([
        'brand_id' => $this->brand->getKey(), 'url' => 'https://ukph.test', 'mode' => SiteMode::Push, 'platform' => SitePlatform::WordPress,
    ])->refresh();

    $reader = Mockery::mock(CertificateReader::class);
    $reader->shouldReceive('read')->andReturn(['ok' => true, 'days' => 80, 'expires' => '1 Jan 2027', 'issuer' => "Let's Encrypt", 'error' => '']);
    app()->instance(CertificateReader::class, $reader);

    inBrand($this->brand);
});

/** @return array<string, SiteCheck> */
function websiteTypeChecks(Site $site): array
{
    return SiteCheck::latestFor([$site->getKey()])[$site->getKey()] ?? [];
}

it('does not call a PHP website WordPress for an old picture, and never guesses Elementor', function (): void {
    $identity = app(SiteIdentity::class);

    expect($identity->platform('<img src="https://ukpublishinghouse.co.uk/wp-content/uploads/2026/01/cover.webp">'))->toBe(SitePlatform::Php)
        ->and($identity->platform('<link rel="stylesheet" href="/wp-content/themes/astra/style.css">'))->toBe(SitePlatform::WordPress)
        ->and($identity->platform('<meta name="generator" content="WordPress 6.6">'))->toBe(SitePlatform::WordPress)
        ->and($identity->platform('<script src="/wp-content/plugins/elementor/frontend.js"></script>'))->toBe(SitePlatform::WordPress);

    $identity->refresh($this->site, '<img src="/wp-content/uploads/cover.webp">');
    expect($this->site->refresh()->platform)->toBe(SitePlatform::Php);

    // A site whose Elementor form sends leads to the webhook keeps that, whatever the homepage says.
    $this->site->update(['platform' => SitePlatform::Elementor]);
    $identity->refresh($this->site, '<html></html>');
    expect($this->site->refresh()->platform)->toBe(SitePlatform::Elementor);
});

it('keeps the type an Admin chose and reads a custom PHP website through liquify.php from then on', function (): void {
    Http::fake(['ukph.test/liquify.php' => Http::response(['ok' => true, 'liquify' => LiquifySnippet::VERSION, 'data' => ['table' => 'leads', 'rows' => 223, 'max_id' => 224]])]);

    Livewire::actingAs($this->admin)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $this->site])
        ->set('tab', 'settings')
        ->assertSee('Website type')
        ->assertSee('Custom PHP website')
        ->assertSet('platform', 'wordpress')
        ->set('platform', 'php')
        ->call('save')
        ->assertHasNoErrors();

    $site = $this->site->refresh();

    expect($site->platform)->toBe(SitePlatform::Php)
        ->and($site->platform_chosen_at)->not->toBeNull()
        ->and($site->mode)->toBe(SiteMode::Pull)
        ->and($site->readsThroughFile())->toBeTrue()
        // It starts after the newest row; older ones come in through Import old leads.
        ->and($site->pull_cursor)->toBe(224);

    // The homepage guess never overrides a chosen type.
    app(SiteIdentity::class)->refresh($site, '<meta name="generator" content="WordPress 6.6">');
    expect($site->refresh()->platform)->toBe(SitePlatform::Php);
});

it('says to download liquify.php again when an older copy cannot read the leads table', function (): void {
    Http::fake(['ukph.test/liquify.php' => Http::response(['ok' => true, 'liquify' => '2.0', 'data' => ['table' => null, 'error' => 'No database settings were found (looked for DB_NAME in includes/config.php).']])]);

    Livewire::actingAs($this->admin)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $this->site])
        ->set('platform', 'php')
        ->call('save')
        ->assertDispatched('toast', fn (string $name, array $toast): bool => $toast['type'] === 'warning' && str_contains((string) $toast['body'], 'download it again'));

    expect($this->site->refresh()->mode)->toBe(SiteMode::Push)
        ->and($this->site->platform)->toBe(SitePlatform::Php);
});

it('makes an Elementor form a webhook website', function (): void {
    $this->site->update(['mode' => SiteMode::Pull, 'pull_config' => ['driver' => 'file'], 'platform' => SitePlatform::Php]);

    Livewire::actingAs($this->admin)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $this->site->refresh()])
        ->set('platform', 'elementor')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->site->refresh()->usesWebhook())->toBeTrue();
});

it('lets the health check read a custom PHP website through liquify.php once the file can', function (): void {
    $this->site->update(['platform' => SitePlatform::Php]);

    Http::fake([
        'ukph.test/liquify.php' => function ($request) {
            return $request->data()['action'] === 'diagnostics'
                ? Http::response(['ok' => true, 'liquify' => LiquifySnippet::VERSION, 'data' => ['checks' => [
                    ['group' => 'database', 'label' => 'Database', 'status' => 'ok', 'message' => 'Connected', 'ms' => 9],
                    ['group' => 'email', 'label' => 'Email', 'status' => 'ok', 'message' => 'Signed in', 'ms' => 40],
                ]]])
                : Http::response(['ok' => true, 'liquify' => LiquifySnippet::VERSION, 'data' => ['table' => 'leads', 'rows' => 5, 'max_id' => 5]]);
        },
        'ukph.test/*' => Http::response('<html><img src="/wp-content/uploads/a.webp"></html>', 200),
    ]);

    expect(app(SiteHealthChecker::class)->run($this->site))->toBe(CheckStatus::Ok);

    $site = $this->site->refresh();

    expect($site->readsThroughFile())->toBeTrue()
        ->and($site->pull_cursor)->toBe(5)
        ->and(websiteTypeChecks($site)['database']->status)->toBe(CheckStatus::Ok);
});

it('turns the database check amber when liquify.php is there but cannot read the leads table', function (): void {
    Http::fake([
        'ukph.test/liquify.php' => Http::response(['ok' => true, 'liquify' => '2.0', 'data' => ['table' => null, 'error' => 'No database settings were found (looked for DB_NAME in includes/config.php).']]),
        // An old WordPress picture: the guess makes it custom PHP first.
        'ukph.test/*' => Http::response('<html><img src="/wp-content/uploads/a.webp"></html>', 200),
    ]);

    expect(app(SiteHealthChecker::class)->run($this->site))->toBe(CheckStatus::Warn);

    $database = websiteTypeChecks($this->site)['database'];

    expect($this->site->refresh()->mode)->toBe(SiteMode::Push)
        ->and($this->site->platform)->toBe(SitePlatform::Php)
        ->and($database->status)->toBe(CheckStatus::Warn)
        ->and($database->message)->toContain('cannot read the leads table yet')->toContain('download it again');
});

it('hands out a liquify.php whose version and send lines match LiquifyCRM', function (): void {
    expect(LiquifySnippet::file($this->site))->toContain("const LIQUIFY_VERSION = '".LiquifySnippet::VERSION."';")
        ->and(LiquifySnippet::lines())->toContain('liquify_send($lead[\'form_type\'], $db, $lead);')
        ->and(LiquifySnippet::isOutdated('2.0'))->toBeTrue()
        ->and(LiquifySnippet::isOutdated(LiquifySnippet::VERSION))->toBeFalse()
        ->and(LiquifySnippet::isOutdated(''))->toBeFalse();
});

/*
 * liquify.php itself, run by PHP in a folder laid out like each kind of brand
 * site: what it finds, as the website keeps it.
 */
it('finds the database details wherever a brand site keeps them', function (array $files, array $expected): void {
    $root = storage_path('framework/testing/liquify-'.bin2hex(random_bytes(4)));

    foreach ([...$files, 'liquify.php' => LiquifySnippet::file($this->site), 'run.php' => <<<'PHP'
        <?php
        $_SERVER['SCRIPT_FILENAME'] = __FILE__;
        set_error_handler(function () { return true; });
        require __DIR__ . '/liquify.php';
        $liquify_config = liquify_config_to_run();
        if ($liquify_config !== null) { ob_start(); include_once $liquify_config; ob_end_clean(); }
        echo json_encode(liquify_settings());
        PHP] as $path => $code) {
        @mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $code);
    }

    $result = Process::run([PHP_BINARY, $root.'/run.php']);
    $settings = json_decode($result->output(), true);

    expect($result->successful())->toBeTrue()
        ->and($settings['file'] ?? null)->toBe($expected['file'])
        ->and(array_intersect_key($settings['values'] ?? [], $expected['values']))->toBe($expected['values']);

    File::deleteDirectory($root);
})->with([
    'written out with define' => [
        ['includes/config.php' => "<?php\ndefine('DB_HOST', 'localhost');\ndefine('DB_NAME', 'irelandph');\ndefine('DB_USER', 'reader');\ndefine('DB_PASS', 'secret');"],
        ['file' => 'includes/config.php', 'values' => ['DB_NAME' => 'irelandph', 'DB_USER' => 'reader']],
    ],
    'const with a prefix in includes/db.php' => [
        ['includes/config.php' => "<?php\n\$BRAND = ['name' => 'Sydney'];\nrequire_once __DIR__ . '/db.php';", 'includes/db.php' => "<?php\nconst SBP_DB_HOST = 'localhost';\nconst SBP_DB_NAME = 'sydney';\nconst SBP_DB_USER = 'reader';\nconst SBP_DB_PASS = 'secret';\nclass Shelf { const DB_NAME = 'not-this'; }"],
        ['file' => 'includes/db.php', 'values' => ['DB_NAME' => 'sydney', 'DB_USER' => 'reader']],
    ],
    'a $DB list' => [
        ['includes/config.php' => "<?php\n\$SMTP = ['enabled' => true, 'host' => 'smtp.gmail.com', 'port' => 587, 'user' => 'hello@ukph.test', 'pass' => 'app-pass'];\n\$DB = ['host' => 'localhost', 'name' => 'ukph', 'user' => 'reader', 'pass' => 'secret', 'charset' => 'utf8mb4'];"],
        ['file' => 'includes/config.php', 'values' => ['DB_NAME' => 'ukph', 'DB_USER' => 'reader', 'SMTP_HOST' => 'smtp.gmail.com', 'SMTP_ENABLED' => '1']],
    ],
    'saved in the admin panel' => [
        [
            'includes/config.php' => "<?php\n\$DB = ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''];\n\$o = json_decode((string) @file_get_contents(__DIR__ . '/../admin/data/settings-overrides.json'), true);\nif (!empty(\$o['db'])) { \$DB = array_merge(\$DB, \$o['db']); }",
            'admin/data/settings-overrides.json' => '{"db":{"name":"vam_live","user":"vam_reader","pass":"secret"}}',
        ],
        ['file' => 'includes/config.php', 'values' => ['DB_NAME' => 'vam_live', 'DB_USER' => 'vam_reader']],
    ],
    'worked out by a settings function' => [
        ['includes/config.php' => "<?php\nfunction vph_setting(\$k, \$d) { \$s = ['db_name' => 'vph_admin']; return \$s[\$k] ?? \$d; }\ndefine('DB_HOST', vph_setting('db_host', 'localhost'));\ndefine('DB_NAME', vph_setting('db_name', 'vph_default'));\ndefine('DB_USER', vph_setting('db_user', 'reader'));"],
        ['file' => 'includes/config.php', 'values' => ['DB_NAME' => 'vph_admin', 'DB_USER' => 'reader']],
    ],
]);
