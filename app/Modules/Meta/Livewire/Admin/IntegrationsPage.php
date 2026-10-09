<?php

declare(strict_types=1);

namespace App\Modules\Meta\Livewire\Admin;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Meta\Actions\ConnectFacebook;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Meta\Support\MetaApp;
use App\Modules\Meta\Support\MetaGraph;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Models\InboundEvent;
use App\Modules\Sites\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Integrations, in three tabs: Facebook (connect once for all brands in
 * three guided steps, then map each Page to its brand), Websites (every
 * brand's websites in one list) and Received (everything websites and
 * Facebook sent, with Retry).
 */
#[Layout('components.layouts.app')]
#[Title('Integrations')]
final class IntegrationsPage extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithPagination;
    use WithToasts;

    /** facebook | websites | received */
    #[Url(except: 'facebook')]
    public string $tab = 'facebook';

    #[Url(except: '')]
    public string $eventSource = '';

    #[Url(except: '')]
    public string $eventStatus = '';

    public string $systemToken = '';

    public bool $showSystemToken = false;

    /** @var array{message: string, type: string}|null */
    public ?array $notice = null;

    public function mount(): void
    {
        $this->authorize(Permission::MetaManage->value);

        $notice = session('integration_notice');
        $this->notice = is_array($notice) ? ['message' => (string) ($notice['message'] ?? ''), 'type' => (string) ($notice['type'] ?? 'info')] : null;
    }

    public function paginationView(): string
    {
        return 'pagination.lq';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['eventSource', 'eventStatus'], true)) {
            $this->resetPage();
        }
    }

    /** Production: a Business Manager System User token does not expire. */
    public function saveSystemToken(ConnectFacebook $connect): void
    {
        $this->authorize(Permission::MetaManage->value);
        $this->validate(['systemToken' => ['required', 'string', 'min:20', 'max:1000']], attributes: ['systemToken' => 'token']);

        try {
            $connection = $connect->handle(trim($this->systemToken), null, auth()->user(), systemUser: true);
        } catch (MetaException $exception) {
            $this->addError('systemToken', 'Facebook did not accept this token: '.$exception->getMessage());

            return;
        }

        $this->systemToken = '';
        $this->showSystemToken = false;
        $this->toastSuccess('Facebook connected as '.$connection->fb_user_name, 'Map each Page to its brand.');
    }

    public function refreshPages(ConnectFacebook $connect): void
    {
        $this->authorize(Permission::MetaManage->value);

        $found = 0;

        try {
            foreach (MetaConnection::query()->get() as $connection) {
                $found += $connect->syncPages($connection);
                $connection->update(['error' => null]);
            }
        } catch (MetaException $exception) {
            $this->toastDanger('Facebook could not be reached', $exception->tokenExpired() ? 'The connection has expired. Click Connect Facebook again.' : $exception->getMessage());

            return;
        }

        Audit::log('integrations.pages_refreshed', null, ['pages' => $found], 'Facebook Pages refreshed', logName: 'integrations');
        $this->toastSuccess($found.' '.str('Page')->plural($found).' up to date');
    }

    public function mapPage(int $pageId, string $brandId): void
    {
        $this->authorize(Permission::MetaManage->value);

        $page = MetaSource::query()->findOrFail($pageId);
        $brand = $brandId === '' ? null : Brand::query()->findOrFail((int) $brandId);

        $page->update(['brand_id' => $brand?->getKey()]);

        Audit::log('integrations.page_mapped', $page, ['page' => $page->name, 'brand' => $brand?->name], $brand ? 'Page "'.$page->name.'" mapped to '.$brand->name : 'Page "'.$page->name.'" unmapped', brand: $brand, logName: 'integrations');

        if ($brand === null) {
            $this->toastWarning($page->name.' is not mapped', 'Its leads and chats wait until it has a brand.');

            return;
        }

        // Anything that arrived while the Page had no brand is processed now.
        $waiting = InboundEvent::query()
            ->whereIn('source', [ProcessMetaEvent::LEADGEN, ProcessMetaEvent::MESSAGE])
            ->where('status', InboundStatus::Failed->value)
            ->where('payload->page_id', $page->external_id)
            ->limit(500)
            ->get();

        $waiting->each->process();

        $this->toastSuccess($page->name.' now belongs to '.$brand->name, $waiting->isNotEmpty() ? $waiting->count().' waiting '.str('event')->plural($waiting->count()).' queued again.' : null);
    }

    public function confirmDisconnect(): void
    {
        $this->authorize(Permission::MetaManage->value);

        $this->askConfirmation('disconnect', [], 'Disconnect Facebook?', 'Leads and Messenger chats stop arriving for every brand. Leads and chats already received stay.', 'Disconnect', 'danger', password: true);
    }

    public function disconnect(): void
    {
        $this->authorize(Permission::MetaManage->value);

        foreach (MetaSource::query()->get() as $page) {
            try {
                app(MetaGraph::class)->unsubscribe($page);
            } catch (MetaException) {
                // The token may already be dead; removing our copy is what matters.
            }

            $page->update(['access_token' => null, 'subscribed_at' => null, 'meta_connection_id' => null]);
        }

        MetaConnection::query()->delete();

        Audit::log('integrations.facebook_disconnected', null, [], 'Facebook disconnected', logName: 'integrations');
        $this->toastWarning('Facebook disconnected');
    }

    public function retry(int $eventId): void
    {
        $this->authorize(Permission::MetaManage->value);

        $event = InboundEvent::query()->findOrFail($eventId);
        $event->process(sync: true);
        $event->refresh();

        Audit::log('integrations.event_retried', null, ['event_id' => $eventId, 'source' => $event->source, 'status' => $event->status->value], 'Received event retried', brand: $event->brand_id, logName: 'integrations');

        $event->status === InboundStatus::Done
            ? $this->toastSuccess('Processed', 'The lead is in its brand now.')
            : $this->toastDanger('Still failing', $event->error);
    }

    public function render(): View
    {
        $connection = MetaConnection::query()->with('connectedBy')->latest('id')->first();
        $tab = in_array($this->tab, ['facebook', 'websites', 'received'], true) ? $this->tab : 'facebook';
        $sites = $tab === 'websites' ? Site::query()->with('brand')->orderBy('name')->get() : collect();

        return view('meta::livewire.admin.integrations-page', [
            'tab' => $tab,
            'sites' => $sites,
            'siteCount' => Site::query()->count(),
            'failedCount' => InboundEvent::query()->where('status', InboundStatus::Failed->value)->count(),
            'configured' => MetaApp::isConfigured(),
            'verifyTokenSet' => MetaApp::verifyToken() !== '',
            'connection' => $connection,
            'pages' => MetaSource::query()->with('brand')->orderBy('name')->get(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug', 'logo_path', 'favicon_path', 'colors']),
            'events' => InboundEvent::query()
                ->with('lead')
                ->when($this->eventSource !== '', fn ($query) => $query->where('source', $this->eventSource))
                ->when($this->eventStatus !== '', fn ($query) => $query->where('status', $this->eventStatus))
                ->latest('id')
                ->paginate(25),
            'sources' => ['site' => 'Website', ProcessMetaEvent::LEADGEN => 'Facebook lead', ProcessMetaEvent::MESSAGE => 'Messenger'],
            'statuses' => InboundStatus::cases(),
            'brandNames' => Brand::query()->pluck('name', 'id'),
            'brandSlugs' => Brand::query()->pluck('slug', 'id'),
        ]);
    }
}
