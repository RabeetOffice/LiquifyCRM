<?php

declare(strict_types=1);

namespace App\Modules\Sites\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Support\BrandWebsite;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Sites\Enums\SiteMode;
use App\Modules\Sites\Enums\SitePlatform;
use App\Modules\Sites\Livewire\Concerns\EditsPullConfig;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\ConnectionTester;
use App\Modules\Sites\Support\LiquifySnippet;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Connect website in 3 steps: add the website, put liquify.php in its root
 * folder (or paste the webhook address, or give the database details), then
 * Test connection. The website's
 * name fills itself in: the brand's name for the brand's own website, else
 * the name the website gives itself, else its domain. A typed name stays.
 */
#[Layout('components.layouts.app')]
#[Title('Connect website')]
final class SiteWizard extends Component
{
    use AuthorizesRequests;
    use EditsPullConfig;
    use WithToasts;

    public Brand $brand;

    public ?Site $site = null;

    public int $step = 1;

    public string $name = '';

    /** The name filled in last by LiquifyCRM: a name that differs from it was typed, and stays. */
    #[Locked]
    public string $autoName = '';

    public string $url = '';

    /** "php" (one file in the root folder), "elementor" (webhook) or "database" (read straight from it). */
    public string $connection = 'php';

    public string $formPath = '/form-submission.php';

    /** @var list<array{label: string, ok: ?bool, message: string}> */
    public array $results = [];

    public function mount(Brand $brand, ?Site $site = null): void
    {
        $this->brand = $brand;

        if ($site !== null && $site->exists) {
            $this->authorize('update', $site);
            $this->site = $site;
            $this->step = 2;
            $this->fillPullConfig($site);

            return;
        }

        $this->authorize('create', Site::class);
        $this->url = (string) $brand->website;
        $this->name = $this->autoName = $this->nameFor($this->url);
    }

    public function updatedUrl(): void
    {
        if ($this->site !== null || trim($this->url) === '' || ($this->name !== '' && $this->name !== $this->autoName)) {
            return;
        }

        $this->name = $this->autoName = $this->nameFor($this->url);
    }

    public function createSite(): void
    {
        $this->authorize('create', Site::class);
        $this->url = Site::cleanUrl($this->url);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'url' => ['required', 'url:http,https', 'max:255'],
            'connection' => ['required', Rule::in(['php', 'elementor', 'database'])],
            'formPath' => ['nullable', 'string', 'max:190'],
        ], attributes: ['name' => 'website name', 'url' => 'website address', 'formPath' => 'form handler path']);

        $site = Site::query()->create([
            'brand_id' => $this->brand->getKey(),
            'name' => $data['name'],
            'url' => $data['url'],
            'domain' => Site::domainOf($data['url']),
            // A PHP website is read through liquify.php; an Elementor form sends each lead itself.
            'mode' => $data['connection'] === 'elementor' ? SiteMode::Push : SiteMode::Pull,
            'platform' => $data['connection'] === 'elementor' ? SitePlatform::Elementor : SitePlatform::Php,
            'pull_config' => match ($data['connection']) {
                'php' => ['driver' => 'file'],
                'database' => ['driver' => 'database'],
                default => null,
            },
            'form_path' => $data['connection'] === 'php' && filled($data['formPath']) ? '/'.ltrim((string) $data['formPath'], '/') : null,
            'created_by' => auth()->id(),
        ]);

        Audit::log('sites.created', $site, ['name' => $site->name, 'domain' => $site->domain, 'mode' => $site->mode->value], 'Website added: '.$site->domain, logName: 'sites');

        $this->redirectRoute('brand.sites.connect', ['brand' => $this->brand->slug, 'site' => $site], navigate: true);
    }

    public function savePull(): void
    {
        $site = $this->requireSite();
        $this->authorize('update', $site);

        $site->update(['pull_config' => $this->validatedPullConfig($site), 'pull_cursor' => null]);

        Audit::log('sites.updated', $site, ['pull' => true], 'Connection details saved for '.$site->domain, logName: 'sites');

        $this->step = 3;
        $this->test(app(ConnectionTester::class));
    }

    public function goTo(int $step): void
    {
        if ($this->site !== null && in_array($step, [2, 3], true)) {
            $this->step = $step;
        }
    }

    public function test(ConnectionTester $tester): void
    {
        $site = $this->requireSite();
        $this->authorize('update', $site);

        $site->loadMissing('brand');
        $this->results = $tester->run($site);
        $this->site = $site->refresh();

        Audit::log('sites.tested', $site, ['results' => array_column($this->results, 'ok', 'label')], 'Connection tested for '.$site->domain, logName: 'sites');
    }

    public function render(): View
    {
        return view('sites::livewire.app.site-wizard', [
            'lines' => LiquifySnippet::lines(),
        ]);
    }

    private function nameFor(string $url): string
    {
        $domain = trim($url) !== '' ? Site::domainOf($url) : '';

        if ($domain === '' || (filled($this->brand->website) && Site::domainOf((string) $this->brand->website) === $domain)) {
            return $this->brand->name;
        }

        return rescue(fn (): ?string => app(BrandWebsite::class)->siteName(Site::cleanUrl($url)), null, false) ?? $domain;
    }

    private function requireSite(): Site
    {
        abort_if($this->site === null, 404);

        return $this->site;
    }
}
