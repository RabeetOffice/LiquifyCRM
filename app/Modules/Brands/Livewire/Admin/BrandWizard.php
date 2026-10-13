<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\Admin;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandIdentity;
use App\Modules\Brands\Support\BrandWebsite;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\Timezones;
use App\Modules\Departments\Actions\CreateStarterTeams;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Support\StarterTeams;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Add brand in 4 steps. The basics: enter the website and its name, link,
 * currency, time zone, phone, email and address fill themselves in. The
 * look: the logo, favicon and colour fetched from the website, adjustable.
 * The teams: the usual departments in one click. And a check at the end
 * that the brand is ready to receive leads.
 */
#[Layout('components.layouts.app')]
#[Title('Add brand')]
final class BrandWizard extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithToasts;

    /** What the website can fill in, and the word for each in the note under the link. */
    private const FILLED = [
        'name' => 'name', 'currency' => 'currency', 'timezone' => 'time zone', 'phone' => 'phone', 'email' => 'email',
        'address' => 'address', 'legalName' => 'company name', 'registrationNumber' => 'company number', 'taxNumber' => 'tax number',
    ];

    public ?Brand $brand = null;

    public int $step = 1;

    /** Step 1 shows the website field alone until it was read, or the Admin chose to type everything. */
    public bool $ready = false;

    public string $name = '';

    public string $slug = '';

    public string $website = '';

    public string $currency = 'GBP';

    public string $timezone = 'Europe/London';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $legalName = '';

    public string $registrationNumber = '';

    public string $taxNumber = '';

    public string $primaryColor = '#FFFFFF';

    public string $secondaryColor = '';

    public ?TemporaryUploadedFile $logo = null;

    /** @var list<string> fields somebody typed: the website never replaces these */
    public array $typed = [];

    public ?string $websiteNote = null;

    /** The website answered and was read (the note is good news), or not. */
    public bool $websiteRead = false;

    /** The website's own pictures, shown in the preview before the brand exists. */
    public ?string $foundIcon = null;

    public ?string $foundLogo = null;

    /** @var list<string> the starter departments to create (keys of StarterTeams) */
    public array $teams = [];

    /** @var list<array{label: string, ok: ?bool, message: string}> */
    public array $checks = [];

    public function mount(?int $brandId = null): void
    {
        $this->authorize('create', Brand::class);

        $this->currency = (string) config('liquify.default_currency');
        $this->timezone = (string) config('liquify.default_timezone');
        $this->teams = StarterTeams::keys();

        // Coming back to a brand that was started: continue at the look.
        if ($brandId !== null) {
            $this->brand = Brand::query()->findOrFail($brandId);
            $this->authorize('update', $this->brand);
            $this->step = 2;
            $this->primaryColor = $this->brand->colors['primary'] ?? '#FFFFFF';
            $this->secondaryColor = $this->brand->colors['secondary'] ?? '';
        }
    }

    /** What somebody changes by hand is theirs: a later read of the website leaves it alone. */
    public function updated(string $property): void
    {
        if ((isset(self::FILLED[$property]) || $property === 'slug') && ! in_array($property, $this->typed, true)) {
            $this->typed[] = $property;
        }
    }

    public function updatedName(string $value): void
    {
        if (! in_array('slug', $this->typed, true)) {
            $this->slug = $value !== '' ? $this->freeSlug(Str::slug($value)) : '';
        }
    }

    /** The link was changed in the form: read the new website. */
    public function updatedWebsite(): void
    {
        $this->fillFromWebsite();
    }

    /** "Read website": the one field of the first screen. */
    public function readWebsite(): void
    {
        $this->validate(['website' => ['required', 'string', 'max:255']], ['website.required' => 'Enter the website first, or fill the brand in by hand.']);

        // The link was read when it was typed; this only opens the rest of the form.
        if ($this->websiteNote === null) {
            $this->fillFromWebsite();
        }

        $this->ready = true;
    }

    public function byHand(): void
    {
        $this->resetValidation();
        $this->ready = true;
    }

    /** Step 1: create the brand, then bring its logo, favicon and colour from the website. */
    public function createBrand(BrandIdentity $identity): void
    {
        $this->authorize('create', Brand::class);

        $this->website = $this->withScheme($this->website);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('brands', 'slug')],
            'website' => ['nullable', 'url', 'max:255'],
            'currency' => ['required', Rule::in((array) config('liquify.currencies'))],
            'timezone' => ['required', 'timezone:all'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'registrationNumber' => ['nullable', 'string', 'max:60'],
            'taxNumber' => ['nullable', 'string', 'max:60'],
        ], attributes: ['slug' => 'link', 'legalName' => 'company name', 'registrationNumber' => 'company number', 'taxNumber' => 'tax number', 'timezone' => 'time zone']);

        $brand = Brand::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'website' => $data['website'] ?: null,
            'currency' => $data['currency'],
            'timezone' => $data['timezone'],
            'is_active' => true,
            'settings' => [
                'reveal_limit_per_hour' => (int) config('liquify.reveal_limit_per_hour'),
                'notification_emails' => [],
                'company' => array_filter([
                    'legal_name' => trim((string) $data['legalName']),
                    'phone' => trim((string) $data['phone']),
                    'email' => mb_strtolower(trim((string) $data['email'])),
                    'address' => trim((string) $data['address']),
                    'registration_number' => trim((string) $data['registrationNumber']),
                    'tax_number' => trim((string) $data['taxNumber']),
                ]),
            ],
            'created_by' => auth()->id(),
        ]);

        Audit::log('brands.created', $brand, ['name' => $brand->name], 'Brand created', brand: $brand, logName: 'brands');

        if (filled($brand->website)) {
            $identity->fetch($brand);
        }

        $this->redirectRoute('admin.brands.create', ['brandId' => $brand->getKey()], navigate: true);
    }

    public function fetchAgain(BrandIdentity $identity): void
    {
        $brand = $this->requireBrand();
        $result = $identity->fetch($brand, fresh: true);

        if ($result['error'] !== null) {
            $this->toast('Nothing fetched', $result['error'], 'warning');

            return;
        }

        $this->primaryColor = $result['colors']['primary'] ?? $this->primaryColor;
        $this->secondaryColor = $result['colors']['secondary'] ?? $this->secondaryColor;
        $this->brand = $brand->refresh();
        $this->toastSuccess('Fetched from the website');
    }

    /** Step 2: keep the logo and colours, then offer the teams. */
    public function saveLook(): void
    {
        $brand = $this->requireBrand();

        $data = $this->validate([
            'primaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ], attributes: ['primaryColor' => 'main colour', 'secondaryColor' => 'second colour']);

        $attributes = ['colors' => array_filter(['primary' => $data['primaryColor'] ?: null, 'secondary' => $data['secondaryColor'] ?: null])];

        if ($this->logo !== null) {
            $fetched = (string) $brand->logo_path;
            $attributes['logo_path'] = $this->logo->store('brands/logos', 'public');
            $this->logo = null;

            // The logo taken from the website gives way to the uploaded one.
            if (str_contains($fetched, '/fetched-')) {
                Storage::disk('public')->delete($fetched);
            }
        }

        $brand->update($attributes);

        $this->step = 3;
    }

    /** Step 3: the chosen starter departments, in one click. */
    public function createTeams(CreateStarterTeams $create): void
    {
        $brand = $this->requireBrand();
        $this->authorize('create', Department::class);

        $made = $create->handle($brand, array_values(array_intersect(StarterTeams::keys(), $this->teams)), auth()->user());

        if ($made !== []) {
            $this->toastSuccess(count($made).' '.str('department')->plural(count($made)).' created', 'Add a Manager and the people of each next.');
        }

        $this->step = 4;
        $this->runChecks();
    }

    public function skipTeams(): void
    {
        $this->requireBrand();
        $this->step = 4;
        $this->runChecks();
    }

    /** Step 4: the test at the end. */
    public function runChecks(): void
    {
        $brand = $this->requireBrand()->refresh();
        $statuses = LeadStatus::query()->forBrand($brand)->count();
        $company = $brand->company();
        $missing = array_keys(array_filter(['a phone number' => $company['phone'] === '', 'an email' => $company['email'] === '', 'an address' => $company['address'] === '']));
        $departments = Department::query()->forBrand($brand)->orderBy('name')->pluck('name')->all();
        $look = array_filter([$brand->logo_path !== null ? 'logo' : null, $brand->favicon_path !== null ? 'favicon' : null, $brand->primaryColor() !== null ? 'colour' : null]);

        $this->checks = [
            ['label' => 'Brand workspace', 'ok' => true, 'message' => $brand->name.' is live at '.$brand->url().', in '.$brand->currency.' and on '.str_replace('_', ' ', Str::afterLast($brand->timezone, '/')).' time.'],
            ['label' => 'Lead pipeline', 'ok' => $statuses > 0, 'message' => $statuses > 0 ? $statuses.' statuses ready, from New to Won and Lost.' : 'The statuses are missing. Open the brand and save it again.'],
            $this->websiteCheck($brand),
            ['label' => 'Logo and colours', 'ok' => $look !== [] ? true : null, 'message' => $look !== [] ? 'In place: '.implode(', ', $look).'.'.($brand->logo_path === null ? ' No logo yet: upload one under Edit brand before sending documents.' : '') : 'Nothing found on the website. Upload a logo and pick a colour under Edit brand.'],
            ['label' => 'Company details', 'ok' => $missing === [] ? true : null, 'message' => $missing === [] ? 'Phone, email and address are in place for invoices and contracts.' : 'Still missing: '.implode(', ', $missing).'. Add under Edit brand.'],
            ['label' => 'Departments', 'ok' => $departments !== [] ? true : null, 'message' => $departments !== [] ? implode(', ', $departments).'. Add a Manager and the people of each next.' : 'None yet. Leads wait in the queue until a department exists.'],
        ];

        $this->brand = $brand;
    }

    public function back(): void
    {
        if ($this->step > 2) {
            $this->step--;
        }
    }

    public function render(): View
    {
        // The colours as they are on screen now, read the way every other screen reads a brand's.
        $look = new Brand(['colors' => array_filter(['primary' => $this->primaryColor, 'secondary' => $this->secondaryColor])]);

        return view('brands::livewire.admin.brand-wizard', [
            'accent' => $look->primaryColor(),
            'tile' => $look->tileColor(),
            'currencies' => (array) config('liquify.currencies'),
            'timezones' => $this->step === 1 ? Timezones::options() : [],
            'starters' => $this->step === 3 ? StarterTeams::all() : [],
            'existing' => $this->step === 3 && $this->brand !== null ? Department::query()->forBrand($this->brand)->orderBy('name')->pluck('name')->all() : [],
            'hasDepartments' => $this->step === 4 && $this->brand !== null && Department::query()->forBrand($this->brand)->exists(),
        ]);
    }

    /**
     * Reads the website and fills in everything it says, except what
     * somebody already typed. When the site cannot be read, its address
     * still gives a starting name.
     */
    private function fillFromWebsite(): void
    {
        $this->websiteNote = null;
        $this->websiteRead = false;
        $this->foundIcon = null;
        $this->foundLogo = null;

        $url = $this->withScheme($this->website);
        $this->website = $url;
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            return;
        }

        $found = app(BrandWebsite::class)->details($url);

        $values = [
            'name' => $found['name'] ?? Str::headline((string) Str::of($host)->replaceStart('www.', '')->before('.')),
            'currency' => $found['currency'],
            'timezone' => $found['timezone'],
            'phone' => $found['phone'],
            'email' => $found['email'],
            'address' => $found['address'],
            'legalName' => $found['legal_name'],
            'registrationNumber' => $found['registration_number'],
            'taxNumber' => $found['tax_number'],
        ];

        $filled = [];

        foreach ($values as $field => $value) {
            if ($value === null || $value === '' || in_array($field, $this->typed, true)) {
                continue;
            }

            $this->{$field} = $value;
            $filled[] = self::FILLED[$field];
        }

        if (in_array('name', $filled, true) && ! in_array('slug', $this->typed, true)) {
            $this->slug = $this->freeSlug(Str::slug($this->name));
        }

        $this->websiteRead = $found['reachable'];
        $this->foundIcon = $found['icon'];
        $this->foundLogo = $found['logo'];
        $this->websiteNote = match (true) {
            ! $found['reachable'] => 'The website could not be read'.($filled !== [] ? ', so only the name comes from its address' : '').'. Fill in the rest by hand.',
            $filled === [] => 'The website was read, but everything here was already typed by hand.',
            default => 'Filled in from the website: '.implode(', ', $filled).'. Change anything that is not right.',
        };
    }

    private function withScheme(string $website): string
    {
        $website = trim($website);

        return $website === '' || preg_match('~^https?://~i', $website) ? $website : 'https://'.$website;
    }

    /** The first link nobody uses yet: name, name-2, name-3. */
    private function freeSlug(string $slug): string
    {
        $base = $slug !== '' ? mb_substr($slug, 0, 70) : 'brand';
        $candidate = $base;
        $number = 1;

        while (Brand::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.(++$number);
        }

        return $candidate;
    }

    /** @return array{label: string, ok: ?bool, message: string} */
    private function websiteCheck(Brand $brand): array
    {
        if (blank($brand->website)) {
            return ['label' => 'Website', 'ok' => null, 'message' => 'No website entered yet. Add it under Edit brand before connecting forms.'];
        }

        try {
            $code = Http::timeout(10)->connectTimeout(5)->withUserAgent('LiquifyCRM')->get(rtrim((string) $brand->website, '/').'/')->status();
        } catch (Throwable) {
            return ['label' => 'Website', 'ok' => false, 'message' => 'The website could not be reached. Check the address under Edit brand.'];
        }

        return $code < 400
            ? ['label' => 'Website', 'ok' => true, 'message' => 'The website answers. Connect its forms next.']
            : ['label' => 'Website', 'ok' => false, 'message' => 'The website answered with error '.$code.'.'];
    }

    private function requireBrand(): Brand
    {
        abort_if($this->brand === null, 404);
        $this->authorize('update', $this->brand);

        return $this->brand;
    }
}
