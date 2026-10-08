<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\Admin;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandIdentity;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\LeadStatus;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Add brand in 3 steps: the basics, the look (favicon and colours fetched
 * from the website, adjustable), and a check at the end that the brand is
 * ready to receive leads.
 */
#[Layout('components.layouts.app')]
#[Title('Add brand')]
final class BrandWizard extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public ?Brand $brand = null;

    public int $step = 1;

    public string $name = '';

    public string $slug = '';

    public string $website = '';

    public string $currency = 'GBP';

    public string $timezone = 'Europe/London';

    public string $primaryColor = '#FF6B2C';

    public string $secondaryColor = '';

    /** @var list<array{label: string, ok: ?bool, message: string}> */
    public array $checks = [];

    public function mount(?int $brandId = null): void
    {
        $this->authorize('create', Brand::class);

        $this->currency = (string) config('liquify.default_currency');
        $this->timezone = (string) config('liquify.default_timezone');

        // Coming back to a brand that was started: continue at the look.
        if ($brandId !== null) {
            $this->brand = Brand::query()->findOrFail($brandId);
            $this->authorize('update', $this->brand);
            $this->step = 2;
            $this->primaryColor = $this->brand->colors['primary'] ?? '#FF6B2C';
            $this->secondaryColor = $this->brand->colors['secondary'] ?? '';
        }
    }

    public function updatedName(string $value, ?string $old = null): void
    {
        if ($this->slug === '' || $this->slug === Str::slug((string) $old)) {
            $this->slug = Str::slug($value);
        }
    }

    /** Step 1: create the brand, then read its website for the look. */
    public function createBrand(BrandIdentity $identity): void
    {
        $this->authorize('create', Brand::class);

        if ($this->website !== '' && ! preg_match('~^https?://~i', $this->website)) {
            $this->website = 'https://'.$this->website;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('brands', 'slug')],
            'website' => ['nullable', 'url', 'max:255'],
            'currency' => ['required', Rule::in((array) config('liquify.currencies'))],
            'timezone' => ['required', 'timezone:all'],
        ], attributes: ['slug' => 'address']);

        $brand = Brand::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'website' => $data['website'] ?: null,
            'currency' => $data['currency'],
            'timezone' => $data['timezone'],
            'is_active' => true,
            'settings' => ['reveal_limit_per_hour' => (int) config('liquify.reveal_limit_per_hour'), 'notification_emails' => []],
            'created_by' => auth()->id(),
        ]);

        Audit::log('brands.created', $brand, ['name' => $brand->name], 'Brand created', brand: $brand, logName: 'brands');

        if (filled($brand->website)) {
            $result = $identity->fetch($brand);
            $this->primaryColor = $result['colors']['primary'] ?? $this->primaryColor;
            $this->secondaryColor = $result['colors']['secondary'] ?? '';
        }

        $this->redirectRoute('admin.brands.create', ['brandId' => $brand->getKey()], navigate: true);
    }

    public function fetchAgain(BrandIdentity $identity): void
    {
        $brand = $this->requireBrand();
        $result = $identity->fetch($brand);

        if ($result['error'] !== null) {
            $this->toast('Nothing fetched', $result['error'], 'warning');

            return;
        }

        $this->primaryColor = $result['colors']['primary'] ?? $this->primaryColor;
        $this->secondaryColor = $result['colors']['secondary'] ?? $this->secondaryColor;
        $this->brand = $brand->refresh();
        $this->toastSuccess('Fetched from the website');
    }

    /** Step 2: keep the colours, then run the check. */
    public function saveLook(): void
    {
        $brand = $this->requireBrand();

        $data = $this->validate([
            'primaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondaryColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], attributes: ['primaryColor' => 'primary colour', 'secondaryColor' => 'secondary colour']);

        $brand->update(['colors' => array_filter(['primary' => $data['primaryColor'] ?: null, 'secondary' => $data['secondaryColor'] ?: null])]);

        $this->step = 3;
        $this->runChecks();
    }

    /** Step 3: the test at the end. */
    public function runChecks(): void
    {
        $brand = $this->requireBrand()->refresh();
        $statuses = LeadStatus::query()->forBrand($brand)->count();

        $this->checks = [
            ['label' => 'Brand workspace', 'ok' => true, 'message' => $brand->name.' is live at '.$brand->url().'.'],
            ['label' => 'Lead pipeline', 'ok' => $statuses > 0, 'message' => $statuses > 0 ? $statuses.' statuses ready, from New to Won and Lost.' : 'The statuses are missing. Open the brand and save it again.'],
            $this->websiteCheck($brand),
            ['label' => 'Favicon and colours', 'ok' => $brand->favicon_path !== null ? true : null, 'message' => $brand->favicon_path !== null ? 'The favicon was fetched from the website.' : 'No favicon found. You can upload one under Edit brand.'],
        ];

        $this->brand = $brand;
    }

    public function back(): void
    {
        if ($this->step === 3) {
            $this->step = 2;
        }
    }

    public function render(): View
    {
        return view('brands::livewire.admin.brand-wizard', [
            'currencies' => (array) config('liquify.currencies'),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
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
