<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\Admin;

use App\Modules\Brands\Jobs\FetchBrandIdentity;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandIdentity;
use App\Modules\Brands\Support\BrandWebsite;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\Timezones;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Add or edit a brand: basics, company details (what its invoices and
 * contracts will carry), look (logo, favicon, colours) and rules (reveal
 * limit, notification emails). The website can fill in the details and the
 * look.
 */
#[Layout('components.layouts.app')]
final class BrandForm extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithToasts;

    /** A company field of this form => its key in the brand and the word for it in a message. */
    private const COMPANY = [
        'legalName' => ['legal_name', 'company name'],
        'phone' => ['phone', 'phone'],
        'email' => ['email', 'email'],
        'address' => ['address', 'address'],
        'registrationNumber' => ['registration_number', 'company number'],
        'taxNumber' => ['tax_number', 'tax number'],
    ];

    public ?Brand $brand = null;

    public string $name = '';

    public string $slug = '';

    public string $website = '';

    public string $currency = 'GBP';

    public string $timezone = 'Europe/London';

    public bool $is_active = true;

    public string $legalName = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $registrationNumber = '';

    public string $taxNumber = '';

    public string $primaryColor = '#FFFFFF';

    public string $secondaryColor = '';

    public ?TemporaryUploadedFile $logo = null;

    public ?TemporaryUploadedFile $favicon = null;

    public int $revealLimit = 30;

    public string $notificationEmails = '';

    /** What the brand's invoices carry (19 Oct 2026). */
    public string $invoicePrefix = '';

    public string $invoicePayment = '';

    public string $invoiceNote = '';

    public int $invoiceDueDays = 7;

    public function mount(?int $brandId = null): void
    {
        if ($brandId !== null) {
            $this->brand = Brand::query()->findOrFail($brandId);
            $this->authorize('update', $this->brand);

            $this->name = $this->brand->name;
            $this->slug = $this->brand->slug;
            $this->website = (string) $this->brand->website;
            $this->currency = $this->brand->currency;
            $this->timezone = $this->brand->timezone;
            $this->is_active = $this->brand->is_active;
            $this->primaryColor = $this->brand->colors['primary'] ?? '#FFFFFF';
            $this->secondaryColor = $this->brand->colors['secondary'] ?? '';
            $this->revealLimit = $this->brand->revealLimitPerHour();
            $this->notificationEmails = implode("\n", $this->brand->notificationEmails());

            $invoice = $this->brand->invoiceSettings();
            $this->invoicePrefix = $invoice['prefix'];
            $this->invoicePayment = $invoice['payment_details'];
            $this->invoiceNote = $invoice['note'];
            $this->invoiceDueDays = $invoice['due_days'];

            $company = $this->brand->company();

            foreach (self::COMPANY as $field => [$key]) {
                $this->{$field} = $company[$key];
            }
        } else {
            $this->authorize('create', Brand::class);
            $this->currency = (string) config('liquify.default_currency');
            $this->timezone = (string) config('liquify.default_timezone');
            $this->revealLimit = (int) config('liquify.reveal_limit_per_hour');
        }
    }

    public function updatedName(string $value, ?string $old = null): void
    {
        if ($this->slug === '' || $this->slug === Str::slug((string) $old)) {
            $this->slug = Str::slug($value);
        }
    }

    /** Read the logo, favicon and colour from the website; the Admin can still adjust them before saving. */
    public function fetchIdentity(BrandIdentity $identity): void
    {
        abort_if($this->brand === null, 404);
        $this->authorize('update', $this->brand);

        $this->validate(['website' => ['required', 'url', 'max:255']]);
        $this->brand->update(['website' => $this->website]);

        $result = $identity->fetch($this->brand, fresh: true);

        if ($result['error'] !== null) {
            $this->toast('Nothing fetched', $result['error'], 'warning');

            return;
        }

        $this->primaryColor = $result['colors']['primary'] ?? $this->primaryColor;
        $this->secondaryColor = $result['colors']['secondary'] ?? $this->secondaryColor;

        Audit::log('brands.identity_fetched', $this->brand, ['logo' => $result['logo'], 'favicon' => $result['favicon'], 'colors' => $result['colors']], 'Logo, favicon and colours fetched from the website', brand: $this->brand, logName: 'brands');

        $found = array_filter([$result['logo'] ? 'the logo' : null, $result['favicon'] ? 'the favicon' : null, $result['colors'] !== [] ? 'the colour' : null]);

        $this->toast($found === [] ? 'Nothing found on the website' : 'Fetched '.implode(', ', $found), $found === [] ? 'Upload a logo and pick the colours by hand.' : 'A logo or favicon you uploaded yourself is never replaced. Adjust the colours if you like, then save.', $found === [] ? 'warning' : 'success');
    }

    /** Fill the company details the website states. A field that already has something is left alone. */
    public function fillCompany(BrandWebsite $reader): void
    {
        $this->validate(['website' => ['required', 'url', 'max:255']]);

        $found = $reader->details($this->website, fresh: true);

        if (! $found['reachable']) {
            $this->toast('Nothing filled in', 'The website could not be read.', 'warning');

            return;
        }

        $filled = [];

        foreach (self::COMPANY as $field => [$key, $word]) {
            if ($this->{$field} === '' && filled($found[$key])) {
                $this->{$field} = (string) $found[$key];
                $filled[] = $word;
            }
        }

        $this->toast(
            $filled === [] ? 'Nothing new on the website' : 'Filled in: '.implode(', ', $filled),
            $filled === [] ? 'It states nothing that is not here already. Fields that have something are never replaced.' : 'Check them, then save.',
            $filled === [] ? 'warning' : 'success',
        );
    }

    public function save(): void
    {
        $currencies = (array) config('liquify.currencies');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('brands', 'slug')->ignore($this->brand?->getKey())],
            'website' => ['nullable', 'url', 'max:255'],
            'currency' => ['required', Rule::in($currencies)],
            'timezone' => ['required', 'timezone:all'],
            'is_active' => ['boolean'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'registrationNumber' => ['nullable', 'string', 'max:60'],
            'taxNumber' => ['nullable', 'string', 'max:60'],
            'primaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'favicon' => ['nullable', 'image', 'max:1024'],
            'revealLimit' => ['required', 'integer', 'min:1', 'max:500'],
            'notificationEmails' => ['nullable', 'string'],
            'invoicePrefix' => ['nullable', 'alpha_num:ascii', 'max:10'],
            'invoicePayment' => ['nullable', 'string', 'max:1000'],
            'invoiceNote' => ['nullable', 'string', 'max:500'],
            'invoiceDueDays' => ['required', 'integer', 'min:0', 'max:90'],
        ], attributes: ['slug' => 'link', 'timezone' => 'time zone', 'legalName' => 'company name', 'registrationNumber' => 'company number', 'taxNumber' => 'tax number', 'primaryColor' => 'main colour', 'secondaryColor' => 'second colour', 'invoicePrefix' => 'invoice number prefix', 'invoicePayment' => 'payment details', 'invoiceNote' => 'note', 'invoiceDueDays' => 'days to pay']);

        $emails = collect(preg_split('/[\s,;]+/', $data['notificationEmails'] ?? '') ?: [])
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();

        $company = [];

        foreach (self::COMPANY as $field => [$key]) {
            $company[$key] = $key === 'email' ? mb_strtolower(trim((string) $data[$field])) : trim((string) $data[$field]);
        }

        $attributes = [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'website' => $data['website'] ?: null,
            'currency' => $data['currency'],
            'timezone' => $data['timezone'],
            'is_active' => $data['is_active'],
            'colors' => array_filter(['primary' => $data['primaryColor'] ?: null, 'secondary' => $data['secondaryColor'] ?: null]),
            'settings' => [
                ...($this->brand->settings ?? []),
                'reveal_limit_per_hour' => (int) $data['revealLimit'],
                'notification_emails' => $emails,
                'invoice' => [
                    'prefix' => strtoupper(trim((string) $data['invoicePrefix'])),
                    'payment_details' => trim((string) $data['invoicePayment']),
                    'note' => trim((string) $data['invoiceNote']),
                    'due_days' => (int) $data['invoiceDueDays'],
                ],
                'company' => array_filter($company),
            ],
        ];

        if ($this->logo !== null) {
            $attributes['logo_path'] = $this->logo->store('brands/logos', 'public');
        }

        if ($this->favicon !== null) {
            $attributes['favicon_path'] = $this->favicon->store('brands/favicons', 'public');
        }

        if ($this->brand === null) {
            $brand = Brand::query()->create([...$attributes, 'created_by' => auth()->id()]);

            // A new brand with a website gets its logo, favicon and colour by itself, unless they were chosen here.
            if (filled($brand->website) && $this->favicon === null) {
                FetchBrandIdentity::dispatch((int) $brand->getKey(), blank($data['secondaryColor']) && in_array(mb_strtoupper((string) $data['primaryColor']), ['', '#FFFFFF', '#FF6B2C'], true), $this->logo === null);
            }

            Audit::log('brands.created', $brand, ['name' => $brand->name], 'Brand created', brand: $brand, logName: 'brands');
            $this->toastSuccess($brand->name.' created', 'Now create its departments and invite a Manager.');
        } else {
            $before = [...$this->brand->only(['name', 'slug', 'website', 'currency', 'timezone', 'is_active']), 'company' => array_filter($this->brand->company())];
            $this->brand->update($attributes);
            Audit::log('brands.updated', $this->brand, ['before' => $before, 'after' => [...$this->brand->only(['name', 'slug', 'website', 'currency', 'timezone', 'is_active']), 'company' => array_filter($this->brand->company())]], 'Brand updated', brand: $this->brand, logName: 'brands');
            $this->toastSuccess('Brand saved');
        }

        $this->redirectRoute('admin.brands.index', navigate: true);
    }

    public function render(): View
    {
        // The colours as they are on screen now, read the way every other screen reads a brand's.
        $look = new Brand(['colors' => array_filter(['primary' => $this->primaryColor, 'secondary' => $this->secondaryColor])]);

        return view('brands::livewire.admin.brand-form', [
            'currencies' => (array) config('liquify.currencies'),
            'timezones' => Timezones::options(),
            'accent' => $look->primaryColor(),
            'tile' => $look->tileColor(),
            'title' => $this->brand ? 'Edit '.$this->brand->name : 'Add brand',
        ])->title($this->brand ? 'Edit brand' : 'Add brand');
    }
}
