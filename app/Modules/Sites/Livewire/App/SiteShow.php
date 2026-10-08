<?php

declare(strict_types=1);

namespace App\Modules\Sites\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Sites\Enums\CheckType;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Jobs\ImportSiteHistory;
use App\Modules\Sites\Jobs\IngestLead;
use App\Modules\Sites\Jobs\PullSiteLeads;
use App\Modules\Sites\Livewire\Concerns\EditsPullConfig;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteCheck;
use App\Modules\Sites\Support\SiteHealthChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One website: health checks with their history, the leads it sent (with
 * Retry for any that failed) and its settings.
 */
#[Layout('components.layouts.app')]
final class SiteShow extends Component
{
    use AuthorizesRequests;
    use EditsPullConfig;
    use WithConfirmation;
    use WithPagination;
    use WithToasts;

    public Brand $brand;

    public Site $site;

    #[Url(except: 'health')]
    public string $tab = 'health';

    #[Url(except: '')]
    public string $eventStatus = '';

    public string $name = '';

    public string $url = '';

    public string $formPath = '';

    public bool $isActive = true;

    public bool $showImport = false;

    public string $importFrom = '';

    public string $importUntil = '';

    public function mount(Brand $brand, Site $site): void
    {
        $this->brand = $brand;
        $this->site = $site;
        $this->authorize('view', $site);

        $this->name = $site->name;
        $this->url = $site->url;
        $this->formPath = $site->formPath();
        $this->isActive = $site->is_active;
        $this->fillPullConfig($site);
    }

    public function paginationView(): string
    {
        return 'pagination.lq';
    }

    public function updatedEventStatus(): void
    {
        $this->resetPage();
    }

    public function checkNow(SiteHealthChecker $checker): void
    {
        $this->authorize('update', $this->site);

        set_time_limit(120);
        $this->site->loadMissing('brand');
        $status = $checker->run($this->site);

        Audit::log('sites.checked', $this->site, ['status' => $status->value], 'Health checks run for '.$this->site->name, logName: 'sites');

        $this->toast($status->getLabel(), 'Form, email, database, SSL and uptime were checked just now.', $status->getColor() === 'gray' ? 'info' : $status->getColor());
    }

    public function pullNow(): void
    {
        $this->authorize('update', $this->site);

        PullSiteLeads::dispatchSync((int) $this->site->getKey());
        $this->site->refresh();

        $this->toast('Leads read', $this->site->last_pull_note, str_starts_with((string) $this->site->last_pull_note, 'Failed') ? 'danger' : 'success');
    }

    public function openImport(): void
    {
        $this->authorize('update', $this->site);

        $this->importFrom = now()->subYear()->toDateString();
        $this->importUntil = now()->toDateString();
        $this->showImport = true;
    }

    /** Old leads from the website's own leads table, for a chosen date range. */
    public function importHistory(): void
    {
        $this->authorize('update', $this->site);

        $data = $this->validate([
            'importFrom' => ['required', 'date'],
            'importUntil' => ['required', 'date', 'after_or_equal:importFrom', 'before_or_equal:today'],
        ], attributes: ['importFrom' => 'start date', 'importUntil' => 'end date']);

        if ($this->site->pullDriver() === null) {
            $this->addError('importFrom', 'Add the agent or database details of this website under Settings first.');

            return;
        }

        ImportSiteHistory::dispatch((int) $this->site->getKey(), $data['importFrom'], $data['importUntil'], (int) auth()->id());

        Audit::log('leads.history_import_started', $this->site, ['from' => $data['importFrom'], 'until' => $data['importUntil']], 'Import of old leads started for '.$this->site->domain, logName: 'leads');

        $this->showImport = false;
        $this->toastSuccess('Import started', 'You get a notification when it is done. Nobody is emailed about the old leads.');
    }

    public function retry(int $eventId): void
    {
        $this->authorize('update', $this->site);

        $event = $this->site->events()->findOrFail($eventId);
        IngestLead::dispatchSync((int) $event->getKey());
        $event->refresh();

        Audit::log('sites.event_retried', $this->site, ['event_id' => $eventId, 'status' => $event->status->value], 'Failed lead retried', logName: 'sites');

        $event->status === InboundStatus::Done
            ? $this->toastSuccess('Lead received', 'It is now in the New queue.')
            : $this->toastDanger('Still failing', $event->error);
    }

    public function retryAllFailed(): void
    {
        $this->authorize('update', $this->site);

        $ids = $this->site->events()->where('status', InboundStatus::Failed->value)->limit(200)->pluck('id');

        foreach ($ids as $id) {
            IngestLead::dispatch((int) $id);
        }

        Audit::log('sites.events_retried', $this->site, ['count' => $ids->count()], 'Failed leads queued again', logName: 'sites');

        $this->toastSuccess($ids->count().' failed '.str('lead')->plural($ids->count()).' queued again');
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        $this->url = Site::cleanUrl($this->url);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'url' => ['required', 'url:http,https', 'max:255'],
            'formPath' => ['nullable', 'string', 'max:190'],
            'isActive' => ['boolean'],
        ], attributes: ['name' => 'website name', 'url' => 'website address', 'formPath' => 'form handler path']);

        $pull = $this->validatedPullConfig($this->site);
        $sourceChanged = $pull !== $this->site->pull_config;

        $this->site->update([
            'name' => $data['name'],
            'url' => $data['url'],
            'domain' => Site::domainOf($data['url']),
            'form_path' => filled($data['formPath']) ? '/'.ltrim((string) $data['formPath'], '/') : null,
            'is_active' => $data['isActive'],
            'pull_config' => $pull,
            // A different source starts watching from its own last row.
            'pull_cursor' => $sourceChanged ? null : $this->site->pull_cursor,
        ]);

        Audit::log('sites.updated', $this->site, ['name' => $this->site->name, 'is_active' => $this->site->is_active], 'Website settings saved', logName: 'sites');

        $this->toastSuccess('Website saved');
    }

    public function confirmNewSecret(): void
    {
        $this->authorize('update', $this->site);

        $this->askConfirmation('newSecret', [], 'Create a new secret?', 'The website stops sending leads until you download liquify.php again and upload it. Leads it queues meanwhile are delivered afterwards.', 'Create new secret', 'danger', password: true);
    }

    public function newSecret(): void
    {
        $this->authorize('update', $this->site);

        $this->site->update(['secret' => Site::generateSecret()]);

        Audit::log('sites.secret_rotated', $this->site, [], 'New secret created for '.$this->site->domain, logName: 'sites');

        $this->toastWarning('New secret created', 'Download liquify.php again and upload it to the website.');
    }

    public function confirmDelete(): void
    {
        $this->authorize('delete', $this->site);

        $this->askConfirmation('delete', [], 'Remove '.$this->site->name.'?', 'It stops receiving leads from this website. Leads already received stay in the brand.', 'Remove website', 'danger', password: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->site);

        $this->site->delete();

        Audit::log('sites.deleted', $this->site, ['domain' => $this->site->domain], 'Website removed: '.$this->site->domain, logName: 'sites');

        $this->toastSuccess($this->site->name.' removed');
        $this->redirectRoute('brand.sites.index', ['brand' => $this->brand->slug], navigate: true);
    }

    public function render(): View
    {
        $site = $this->site;
        $latest = SiteCheck::latestFor([$site->getKey()])[$site->getKey()] ?? [];

        return view('sites::livewire.app.site-show', [
            'latest' => $latest,
            'types' => CheckType::cases(),
            'history' => $this->tab === 'health'
                ? $site->checks()->latest('id')->limit(40)->get()
                : collect(),
            'events' => $this->tab === 'leads'
                ? $site->events()
                    ->with('lead')
                    ->when($this->eventStatus !== '', fn ($query) => $query->where('status', $this->eventStatus))
                    ->latest('id')
                    ->paginate(25)
                : null,
            'failedCount' => $site->events()->where('status', InboundStatus::Failed->value)->count(),
            'statuses' => InboundStatus::cases(),
            'canManage' => (bool) auth()->user()?->can('update', $site),
        ])->title($site->name);
    }
}
