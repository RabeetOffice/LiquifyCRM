<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\Admin;

use App\Modules\Brands\Jobs\FetchBrandIdentity;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandIdentity;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Add or edit a brand: basics, look (logo, favicon, colours) and rules
 * (reveal limit, notification emails). The website identity fetch joins
 * the Look section in week 5.
 */
#[Layout('components.layouts.app')]
final class BrandForm extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithToasts;

    public ?Brand $brand = null;

    public string $name = '';

    public string $slug = '';

    public string $website = '';

    public string $currency = 'GBP';

    public string $timezone = 'Europe/London';

    public bool $is_active = true;

    public string $primaryColor = '#FF6B2C';

    public string $secondaryColor = '';

    public ?TemporaryUploadedFile $logo = null;

    public ?TemporaryUploadedFile $favicon = null;

    public int $revealLimit = 30;

    public string $notificationEmails = '';

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
            $this->primaryColor = $this->brand->colors['primary'] ?? '#FF6B2C';
            $this->secondaryColor = $this->brand->colors['secondary'] ?? '';
            $this->revealLimit = $this->brand->revealLimitPerHour();
            $this->notificationEmails = implode("\n", $this->brand->notificationEmails());
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

    /** Read the favicon and colours from the website; the Admin can still adjust them before saving. */
    public function fetchIdentity(BrandIdentity $identity): void
    {
        abort_if($this->brand === null, 404);
        $this->authorize('update', $this->brand);

        $this->validate(['website' => ['required', 'url', 'max:255']]);
        $this->brand->update(['website' => $this->website]);

        $result = $identity->fetch($this->brand);

        if ($result['error'] !== null) {
            $this->toast('Nothing fetched', $result['error'], 'warning');

            return;
        }

        $this->primaryColor = $result['colors']['primary'] ?? $this->primaryColor;
        $this->secondaryColor = $result['colors']['secondary'] ?? $this->secondaryColor;

        Audit::log('brands.identity_fetched', $this->brand, ['favicon' => $result['favicon'], 'colors' => $result['colors']], 'Favicon and colours fetched from the website', brand: $this->brand, logName: 'brands');

        $found = array_filter([$result['favicon'] ? 'the favicon' : null, $result['colors'] !== [] ? count($result['colors']).' '.str('colour')->plural(count($result['colors'])) : null]);

        $this->toast($found === [] ? 'Nothing found on the website' : 'Fetched '.implode(' and ', $found), $found === [] ? 'Upload a favicon and pick the colours by hand.' : 'Adjust the colours if you like, then save.', $found === [] ? 'warning' : 'success');
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
            'primaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'favicon' => ['nullable', 'image', 'max:1024'],
            'revealLimit' => ['required', 'integer', 'min:1', 'max:500'],
            'notificationEmails' => ['nullable', 'string'],
        ]);

        $emails = collect(preg_split('/[\s,;]+/', $data['notificationEmails'] ?? '') ?: [])
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();

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

            // A new brand with a website gets its favicon and colours by itself.
            if (filled($brand->website) && $this->favicon === null) {
                FetchBrandIdentity::dispatch((int) $brand->getKey(), blank($data['primaryColor']) || $data['primaryColor'] === '#FF6B2C');
            }

            Audit::log('brands.created', $brand, ['name' => $brand->name], 'Brand created', brand: $brand, logName: 'brands');
            $this->toastSuccess($brand->name.' created', 'Now create its departments and invite a Manager.');
        } else {
            $before = $this->brand->only(['name', 'slug', 'website', 'currency', 'timezone', 'is_active']);
            $this->brand->update($attributes);
            Audit::log('brands.updated', $this->brand, ['before' => $before, 'after' => $this->brand->only(array_keys($before))], 'Brand updated', brand: $this->brand, logName: 'brands');
            $this->toastSuccess('Brand saved');
        }

        $this->redirectRoute('admin.brands.index', navigate: true);
    }

    public function render(): View
    {
        return view('brands::livewire.admin.brand-form', [
            'currencies' => (array) config('liquify.currencies'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'title' => $this->brand ? 'Edit '.$this->brand->name : 'Add brand',
        ])->title($this->brand ? 'Edit brand' : 'Add brand');
    }
}
