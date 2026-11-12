<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\ChatWidgets;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\WebsiteLook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The chat box customiser (user request, 12 Nov 2026): the launcher's style, colours read from the website, light or dark,
 * header, corners, size, place, devices, greeting and sound. Values go into the website's page as styles, so only checked
 * ones are ever sent.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->manager = managerOf(department($this->brand, ['name' => 'Front Sales']));
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'UK site', 'widget_key' => ChatWidget::newKey(),
        'allowed_domains' => ['ukpublishinghouse.co.uk'],
    ]);

    inBrand($this->brand);
});

it('keeps an older chat box looking as it did', function (): void {
    $design = $this->widget->design();

    expect($design['style'])->toBe('bubble')
        ->and($design['theme'])->toBe('light')
        ->and($design['accent'])->toBe($design['color'])
        ->and($design['offset_x'])->toBe(20)
        ->and($design['greet_after'])->toBe(0);
});

it('saves the customiser and sends the launcher and the chat box only checked values', function (): void {
    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('edit', $this->widget->getKey())
        ->assertSet('tab', 'look')
        ->set('color', '#1B2A6B')->set('accent', '#e11c2b')->set('style', 'pill')->set('theme', 'auto')->set('header', 'glass')
        ->set('corners', 'round')->set('size', 'large')->set('offsetX', 24)->set('offsetY', 96)->set('showOn', 'desktop')
        ->set('greetAfter', 10)->set('sound', false)->set('logo', true)->set('label', 'Talk to us')->set('onlineSub', 'Online now')
        ->call('save')
        ->assertHasNoErrors();

    $design = $this->widget->refresh()->design();

    expect($design)->toMatchArray([
        'color' => '#1b2a6b', 'accent' => '#e11c2b', 'on_accent' => '#ffffff', 'style' => 'pill', 'theme' => 'auto', 'header' => 'glass',
        'radius' => 24, 'width' => 420, 'offset_x' => 24, 'offset_y' => 96, 'show_on' => 'desktop', 'greet_after' => 10, 'sound' => false,
        'label' => 'Talk to us', 'online_sub' => 'Online now',
    ]);

    $this->getJson('/chat/api/config?key='.$this->widget->widget_key, ['Origin' => 'https://www.ukpublishinghouse.co.uk'])
        ->assertOk()->assertJson(['style' => 'pill', 'accent' => '#e11c2b', 'offset_y' => 96, 'greet_after' => 10, 'label' => 'Talk to us']);

    $frame = $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://www.ukpublishinghouse.co.uk'))->assertOk()->getContent();
    expect($frame)->toContain('class="theme-auto h-glass"')->toContain('--a: #e11c2b')->toContain('data-sound="0"');
});

it('refuses values outside the lists and shows the part with the problem', function (): void {
    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('edit', $this->widget->getKey())
        ->set('style', 'red;background:url(x)')->set('accent', 'red')->set('offsetX', 999)
        ->call('save')
        ->assertHasErrors(['style', 'accent', 'offsetX'])
        ->assertSet('tab', 'look');

    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('edit', $this->widget->getKey())
        ->set('domains', '')
        ->call('save')
        ->assertHasErrors(['domains'])
        ->assertSet('tab', 'setup');

    // A value written into the database by other means still never reaches a page as a style.
    $this->widget->update(['appearance' => ['style' => '</style>', 'color' => 'expression(x)', 'accent' => '#zzz', 'offset_x' => '-50', 'corners' => 'x']]);
    expect($this->widget->refresh()->design())->toMatchArray(['style' => 'bubble', 'color' => '#f97316', 'accent' => '#f97316', 'offset_x' => 0, 'radius' => 16]);
});

it('reads a website’s main colour and accent from its page and style sheet', function (): void {
    Http::fake([
        'https://ukpublishinghouse.co.uk/' => Http::response('<html><head><meta name="theme-color" content="#1b2a6b"><link rel="stylesheet" href="/css/site.css"><link rel="stylesheet" href="https://cdn.example.com/bootstrap.min.css"></head><body><a class="btn" style="background:#e11c2b">Get started</a></body></html>'),
        'https://ukpublishinghouse.co.uk/css/site.css' => Http::response(':root{--ink:#1b2a6b;--brand-red:#e11c2b;--paper:#ffffff;--muted:#6e6f86}.btn-primary{background:#e11c2b}header{background-color:#1b2a6b}p{color:#373737}.lilac{background:#efebf7}'),
        '*' => Http::response('', 404),
    ]);

    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('edit', $this->widget->getKey())
        ->call('matchWebsite')
        ->assertSet('color', '#1b2a6b')
        ->assertSet('accent', '#e11c2b')
        ->assertSet('paletteFrom', 'ukpublishinghouse.co.uk');

    // The library's style sheet is never asked for.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'bootstrap'));
});

it('takes a lively accent rather than a pale tint, and gives up on a grey website', function (): void {
    $look = app(WebsiteLook::class);

    $green = $look->fromHtml('<style>:root{--primary:#123f36;--tint:#dcebe5;--tint2:#dcebe5;--cta:#f4a426}header{background:#123f36}.hero{background:#dcebe5}.x{background:#dcebe5}</style>');
    expect($green['color'])->toBe('#123f36')->and($green['accent'])->toBe('#f4a426');

    $grey = $look->fromHtml('<style>body{color:#333;background:#fff}.btn{background:#111}</style>');
    expect($grey['color'])->toBeNull()->and($grey['error'])->not->toBeNull();
});
