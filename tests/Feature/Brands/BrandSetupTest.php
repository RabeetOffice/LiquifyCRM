<?php

declare(strict_types=1);

use App\Modules\Brands\Livewire\Admin\BrandForm;
use App\Modules\Brands\Livewire\Admin\BrandWizard;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandIdentity;
use App\Modules\Core\Support\Countries;
use App\Modules\Core\Support\Timezones;
use App\Modules\Departments\Actions\CreateStarterTeams;
use App\Modules\Departments\Livewire\App\DepartmentsIndex;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Support\StarterTeams;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Add brand from its website: the company details, the currency and time
 * zone that follow from its country, the logo; the time zone search; and
 * the starter departments in one click.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');

    $this->admin = admin();

    inBrand(null);
});

/** A small PNG: one flat colour, or a block of it on a see-through background. */
function logoPicture(string $hex, bool $seeThrough = false): string
{
    $image = imagecreatetruecolor(40, 20);
    imagealphablending($image, false);
    imagesavealpha($image, true);

    [$red, $green, $blue] = array_map(intval(...), (array) sscanf($hex, '#%02x%02x%02x'));

    if ($seeThrough) {
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 4, 4, 22, 14, (int) imagecolorallocate($image, $red, $green, $blue));
    } else {
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, $green, $blue));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('fills a new brand in from what its website says about the company', function (): void {
    $about = json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'WebSite', 'name' => 'Harbour Press'],
        [
            '@type' => ['LocalBusiness', 'Publisher'],
            'name' => 'Harbour Press',
            'alternateName' => 'Harbour Press Ltd',
            'logo' => ['@type' => 'ImageObject', 'url' => 'https://harbourpress.co.uk/img/logo.png'],
            'telephone' => '+44 20 7946 0000',
            'email' => 'Hello@HarbourPress.co.uk',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => '12 Quay Street', 'addressLocality' => 'London', 'addressRegion' => 'England', 'postalCode' => 'N1 7GU', 'addressCountry' => 'GB'],
            'identifier' => ['@type' => 'PropertyValue', 'propertyID' => 'UK Companies House', 'value' => '01234567'],
        ],
    ]]);

    Http::fake([
        'harbourpress.co.uk/img/logo.png' => Http::response(logoPicture('#1E3A8A'), 200, ['Content-Type' => 'image/png']),
        'harbourpress.co.uk/icon.png' => Http::response(logoPicture('#1E3A8A'), 200, ['Content-Type' => 'image/png']),
        'harbourpress.co.uk/*' => Http::response('<html lang="en-GB"><head><meta property="og:site_name" content="Harbour Press"><link rel="icon" href="/icon.png"><script type="application/ld+json">'.$about.'</script></head><body><img src="/img/partners/logo-slider.png" alt="partner logo"></body></html>'),
    ]);

    Livewire::actingAs($this->admin)
        ->test(BrandWizard::class)
        ->set('website', 'harbourpress.co.uk')
        ->call('readWebsite')
        ->assertSet('ready', true)
        ->assertSet('name', 'Harbour Press')
        ->assertSet('slug', 'harbour-press')
        ->assertSet('phone', '+44 20 7946 0000')
        ->assertSet('email', 'hello@harbourpress.co.uk')
        // The nation is not part of a written address in the United Kingdom; the country is.
        ->assertSet('address', "12 Quay Street\nLondon N1 7GU\nUnited Kingdom")
        ->assertSet('legalName', 'Harbour Press Ltd')
        ->assertSet('registrationNumber', '01234567')
        // Its own logo, not the partner's in the page.
        ->assertSet('foundLogo', 'https://harbourpress.co.uk/img/logo.png')
        ->assertSee('Filled in from the website: name, currency, time zone, phone, email, address, company name, company number')
        ->assertSee('Company details')
        ->call('createBrand')
        ->assertHasNoErrors()
        ->assertRedirect();

    $brand = Brand::query()->where('slug', 'harbour-press')->sole();

    expect($brand->company())->toBe([
        'legal_name' => 'Harbour Press Ltd',
        'phone' => '+44 20 7946 0000',
        'email' => 'hello@harbourpress.co.uk',
        'address' => "12 Quay Street\nLondon N1 7GU\nUnited Kingdom",
        'registration_number' => '01234567',
        'tax_number' => '',
    ])
        ->and($brand->legalName())->toBe('Harbour Press Ltd')
        ->and($brand->logo_path)->toBe('brands/logos/fetched-'.$brand->getKey().'.png')
        ->and($brand->logoUrl())->toContain('?v=')
        ->and($brand->favicon_path)->not->toBeNull()
        // No theme colour on the site, so the brand's colour is read from its logo.
        ->and($brand->colors)->toMatchArray(['primary' => '#FFFFFF', 'secondary' => '#1E3A8A']);

    Storage::disk('public')->assertExists($brand->logo_path);
})->skip(! function_exists('imagecreatetruecolor'), 'Needs the GD extension.');

it('works out the currency and time zone from the country and reads the contact page', function (): void {
    Http::fake([
        'riverbooks.com.au/contact/' => Http::response('<html><body><a href="tel:+61892000000"><span>+61 8 9200 0000</span></a> <a href="mailto:hello@riverbooks.com.au?subject=Hi">Email us</a><address>12 Quay Street<br>Perth WA 6000<br><a href="tel:+61892000000">+61 8 9200 0000</a></address></body></html>'),
        'riverbooks.com.au/*' => Http::response('<html><head><title>River Books | Independent publisher</title></head><body><a href="#top">Top</a> <a href="/contact/">Contact us</a></body></html>'),
    ]);

    Livewire::actingAs($this->admin)
        ->test(BrandWizard::class)
        ->set('website', 'https://riverbooks.com.au')
        ->call('readWebsite')
        ->assertSet('name', 'River Books')
        // ".com.au" is Australia, and "Perth WA" is not on Sydney time.
        ->assertSet('currency', 'AUD')
        ->assertSet('timezone', 'Australia/Perth')
        // The homepage names no phone, email or address: the contact page does.
        ->assertSet('phone', '+61 8 9200 0000')
        ->assertSet('email', 'hello@riverbooks.com.au')
        ->assertSet('address', "12 Quay Street\nPerth WA 6000");

    // What somebody chose by hand stays, whatever the website says.
    Livewire::actingAs($this->admin)
        ->test(BrandWizard::class)
        ->set('currency', 'USD')
        ->set('phone', '0800 000 000')
        ->set('website', 'riverbooks.com.au')
        ->call('readWebsite')
        ->assertSet('currency', 'USD')
        ->assertSet('phone', '0800 000 000')
        ->assertSet('timezone', 'Australia/Perth');
});

it('takes the logo from the website, but not a white one and never over an uploaded one', function (): void {
    Http::fake([
        'darkheader.test/logo-white.png' => Http::response(logoPicture('#FFFFFF', seeThrough: true), 200, ['Content-Type' => 'image/png']),
        'darkheader.test/*' => Http::response('<html><body><header><a class="site-logo" href="/"><img src="/logo-white.png" alt="Dark Header"></a></header></body></html>'),
        'colourful.test/brand.png' => Http::response(logoPicture('#B91C1C', seeThrough: true), 200, ['Content-Type' => 'image/png']),
        'colourful.test/evil.svg' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 200, ['Content-Type' => 'image/svg+xml']),
        'colourful.test/*' => Http::response('<html><body><header><div class="logo"><a href="/"><img src="/evil.svg" alt="logo"></a></div><a class="navbar-brand" href="/"><img data-src="/brand.png" src="data:image/gif;base64,R0lGOD" alt="Colourful"></a></header></body></html>'),
    ]);

    $identity = app(BrandIdentity::class);

    // A white logo is made for a dark page: on a white invoice it could not be seen.
    $dark = brand(['website' => 'https://darkheader.test', 'colors' => null]);
    expect($identity->fetch($dark)['logo'])->toBeFalse()
        ->and($dark->refresh()->logo_path)->toBeNull();

    // An SVG that carries a script is refused; the next picture marked as the logo is taken.
    $brand = brand(['website' => 'https://colourful.test', 'colors' => null]);
    $result = $identity->fetch($brand);

    expect($result['logo'])->toBeTrue()
        ->and($brand->refresh()->logo_path)->toBe('brands/logos/fetched-'.$brand->getKey().'.png')
        ->and($brand->colors)->toMatchArray(['primary' => '#FFFFFF', 'secondary' => '#B91C1C']);

    // A logo somebody uploaded is theirs.
    $brand->update(['logo_path' => 'brands/logos/our-own.png']);

    expect($identity->fetch($brand, fresh: true)['logo'])->toBeFalse()
        ->and($brand->refresh()->logo_path)->toBe('brands/logos/our-own.png');
})->skip(! function_exists('imagecreatetruecolor'), 'Needs the GD extension.');

it('keeps the company details on the brand and fills only the empty ones from the website', function (): void {
    Http::fake([
        'ukph.test/*' => Http::response('<html><body><a href="tel:+442081352515">Call</a> <a href="mailto:info@ukph.test">Email</a> <footer>UKPH Ltd. Company No. 15941429. VAT No. GB 123 4567 89</footer></body></html>'),
    ]);

    $brand = brand(['name' => 'UK Publishing House', 'website' => 'https://ukph.test', 'settings' => ['reveal_limit_per_hour' => 12]]);

    expect($brand->legalName())->toBe('UK Publishing House');

    Livewire::actingAs($this->admin)
        ->test(BrandForm::class, ['brandId' => $brand->getKey()])
        ->assertSee('Company details')
        ->set('phone', '0800 111 222')
        ->call('fillCompany')
        // The phone was typed, so it stays; the rest comes from the website.
        ->assertSet('phone', '0800 111 222')
        ->assertSet('email', 'info@ukph.test')
        ->assertSet('registrationNumber', '15941429')
        ->assertSet('taxNumber', 'GB 123 4567 89')
        ->set('legalName', 'UK Publishing House Ltd')
        ->set('address', "60 Tottenham Court Road\nLondon W1T 2EW")
        ->call('save')
        ->assertHasNoErrors();

    $brand->refresh();

    expect($brand->company())->toMatchArray(['legal_name' => 'UK Publishing House Ltd', 'phone' => '0800 111 222', 'email' => 'info@ukph.test', 'address' => "60 Tottenham Court Road\nLondon W1T 2EW", 'registration_number' => '15941429'])
        ->and($brand->legalName())->toBe('UK Publishing House Ltd')
        // The other settings of the brand are untouched.
        ->and($brand->revealLimitPerHour())->toBe(12);

    Livewire::actingAs($this->admin)
        ->test(BrandForm::class, ['brandId' => $brand->getKey()])
        ->set('email', 'not-an-email')
        ->call('save')
        ->assertHasErrors('email');
});

it('finds a time zone by its city or its country', function (): void {
    $zones = collect(Timezones::options())->keyBy('value');

    expect($zones['Europe/London']['label'])->toBe('London')
        ->and($zones['Europe/London']['hint'])->toStartWith('United Kingdom · GMT')
        ->and($zones['Europe/London']['search'])->toContain('uk')
        ->and($zones['America/New_York']['label'])->toBe('New York')
        ->and($zones['Asia/Karachi']['search'])->toContain('pakistan')
        ->and($zones->has(config('liquify.default_timezone')))->toBeTrue()
        // What a website's address, phone or language says about its country.
        ->and(Countries::fromHost('www.example.co.uk'))->toBe('GB')
        ->and(Countries::fromHost('example.io'))->toBeNull()
        ->and(Countries::fromHost('example.com'))->toBeNull()
        ->and(Countries::fromPhone('+61 2 9000 0000'))->toBe('AU')
        ->and(Countries::fromPhone('+1 212 555 0100'))->toBeNull()
        ->and(Countries::fromPhone('020 7946 0000'))->toBeNull()
        ->and(Countries::fromLocale('en_NZ'))->toBe('NZ')
        ->and(Countries::fromLocale('en-US'))->toBeNull()
        ->and(Countries::code('England'))->toBe('GB')
        ->and(Countries::currency('NZ'))->toBe('NZD')
        ->and(Countries::currency('PK'))->toBeNull()
        ->and(Countries::timezone('US', '500 Main St, Austin, TX 78701'))->toBe('America/Chicago')
        ->and(Countries::timezone('US', 'Acme Co, 1 Broadway, New York, NY 10004'))->toBe('America/New_York')
        ->and(Countries::timezone('PK'))->toBe('Asia/Karachi');

    // The search picker replaces the list of four hundred on both brand screens.
    Livewire::actingAs($this->admin)->test(BrandWizard::class)->call('byHand')->assertSee('Search a city or a country')->assertSee('lq-picker', false);
    Livewire::actingAs($this->admin)->test(BrandForm::class, ['brandId' => brand()->getKey()])->assertSee('lq-picker', false);
});

it('creates the usual departments in one click, once', function (): void {
    $brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $create = app(CreateStarterTeams::class);

    expect(StarterTeams::keys())->toBe(['front-sales', 'upsell', 'project-management']);

    $made = $create->handle($brand, StarterTeams::keys(), $this->admin);

    expect(collect($made)->map(fn (Department $department): string => $department->name.':'.$department->kind()->value)->all())
        ->toBe(['Front Sales:sales', 'Upsell:upsell', 'Project Management:upsell'])
        // A second click makes nothing twice.
        ->and($create->handle($brand, StarterTeams::keys(), $this->admin))->toBe([])
        ->and(Department::query()->forBrand($brand)->count())->toBe(3);

    // A starter department that was removed comes back instead of failing on its name.
    Department::query()->forBrand($brand)->where('name', 'Upsell')->sole()->delete();

    expect($create->handle($brand, ['upsell'], $this->admin))->toHaveCount(1)
        ->and(Department::query()->forBrand($brand)->count())->toBe(3);
});

it('offers the usual departments on the Departments screen while a brand has none', function (): void {
    $brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    inBrand($brand);

    Livewire::actingAs($this->admin)
        ->test(DepartmentsIndex::class, ['brand' => $brand])
        ->assertSee('Start with the usual departments')
        ->set('starter', ['front-sales'])
        ->call('createStarter')
        ->assertDontSee('Start with the usual departments')
        ->assertSee('Front Sales');

    expect(Department::query()->forBrand($brand)->pluck('name')->all())->toBe(['Front Sales']);

    // Only an Admin creates departments: a Manager is not offered them, and cannot ask.
    $other = brand(['slug' => 'other']);
    $manager = managerOf(department($other, ['name' => 'Team']));
    Department::query()->forBrand($other)->delete();
    inBrand($other);

    Livewire::actingAs($manager)
        ->test(DepartmentsIndex::class, ['brand' => $other])
        ->assertDontSee('Start with the usual departments')
        ->call('createStarter')
        ->assertForbidden();
});
