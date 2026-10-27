<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadEnquiry;
use App\Modules\Leads\Support\WebsiteLeads;
use App\Modules\Sites\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every website lead of several brands on one screen (user request,
 * 27 Oct 2026): for a Website leads person, the brands an Admin chose for
 * them; for Admins, every brand. Read only: nothing here changes a lead, and
 * Facebook, Messenger, Instagram and hand-made leads never show. Only Admins
 * see the full email and phone; everybody else sees them masked.
 */
#[Layout('components.layouts.app')]
#[Title('Website leads')]
final class WebsiteLeadsBoard extends Component
{
    use WithPagination;

    private const DAYS = 30;

    private const COLORS = ['#F97316', '#6366F1', '#14B8A6', '#F59E0B', '#EC4899', '#0EA5E9', '#84CC16', '#A855F7', '#EF4444', '#10B981', '#64748B'];

    /** all | today | week | waiting */
    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'brand', except: '')]
    public string $brandFilter = '';

    #[Url(as: 'site', except: '')]
    public string $siteFilter = '';

    #[Url(except: '')]
    public string $search = '';

    /** The lead open in the side sheet; chosen on the server after the check. */
    #[Locked]
    public ?int $open = null;

    public bool $showLead = false;

    public function mount(): void
    {
        abort_unless(WebsiteLeads::canOpen($this->viewer()), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'brandFilter', 'siteFilter', 'search'], true)) {
            $this->resetPage();
        }

        if ($property === 'brandFilter') {
            $this->siteFilter = '';
        }
    }

    public function openLead(int $leadId): void
    {
        $lead = WebsiteLeads::query($this->viewer())->findOrFail($leadId);

        $this->open = (int) $lead->getKey();
        $this->showLead = true;
    }

    public function render(): View
    {
        $viewer = $this->viewer();
        $brands = WebsiteLeads::brandsFor($viewer);
        $brandIds = $brands->modelKeys();
        $brandFilter = ctype_digit($this->brandFilter) && in_array((int) $this->brandFilter, $brandIds, true) ? (int) $this->brandFilter : null;
        $shownIds = $brandFilter !== null ? [$brandFilter] : $brandIds;
        $sites = Site::query()->acrossBrands()->whereIn('brand_id', $shownIds === [] ? [0] : $shownIds)->orderBy('name')->get(['id', 'brand_id', 'name', 'domain']);
        $siteFilter = ctype_digit($this->siteFilter) && $sites->contains('id', (int) $this->siteFilter) ? (int) $this->siteFilter : null;

        $scoped = fn (): Builder => WebsiteLeads::query($viewer, $shownIds)
            ->when($siteFilter !== null, fn (Builder $query): Builder => $query->where('source_id', $siteFilter));

        $today = ViewerTime::startOfToday($viewer);
        $week = $today->subDays(6);
        $like = '%'.$this->search.'%';

        $leads = $scoped()
            ->with(['status', 'assignee:id,name,avatar_path'])
            ->when($this->tab === 'today', fn (Builder $query): Builder => $query->where('created_at', '>=', $today))
            ->when($this->tab === 'week', fn (Builder $query): Builder => $query->where('created_at', '>=', $week))
            ->when($this->tab === 'waiting', fn (Builder $query): Builder => $query->unassigned()->open())
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->latest('created_at')->latest('id')
            ->paginate(25);

        $counts = [
            'all' => $scoped()->count(),
            'today' => $scoped()->where('created_at', '>=', $today)->count(),
            'week' => $scoped()->where('created_at', '>=', $week)->count(),
            'waiting' => $scoped()->unassigned()->open()->count(),
        ];

        $lead = $this->showLead && $this->open !== null
            ? WebsiteLeads::query($viewer, $brandIds)->with(['status', 'assignee:id,name', 'department:id,name', 'brand'])->find($this->open)
            : null;

        return view('leads::livewire.website-leads-board', [
            'brands' => $brands,
            'brandsById' => $brands->keyBy('id'),
            'sites' => $sites,
            'siteNames' => $sites->pluck('name', 'id')->all(),
            'leads' => $leads,
            'counts' => $counts,
            'chips' => array_filter([
                'brandFilter' => $brandFilter !== null ? 'Brand: '.$brands->firstWhere('id', $brandFilter)?->name : null,
                'siteFilter' => $siteFilter !== null ? 'Website: '.$sites->firstWhere('id', $siteFilter)?->name : null,
            ]),
            'perDay' => $this->perDay($scoped(), $brands->whereIn('id', $shownIds)->values()->all(), $viewer),
            'bySite' => $this->bySite($scoped(), $sites->pluck('name', 'id')->all()),
            'fullContact' => $viewer->isGlobalAdmin(),
            'lead' => $lead,
            'answers' => $lead !== null ? LeadEnquiry::answers($lead) : [],
            'facts' => $lead !== null ? LeadEnquiry::source($lead) : [],
            'month' => $scoped()->where('created_at', '>=', ViewerTime::now($viewer)->startOfMonth()->setTimezone((string) config('app.timezone')))->count(),
        ]);
    }

    public function removeFilter(string $filter): void
    {
        if (in_array($filter, ['brandFilter', 'siteFilter'], true)) {
            $this->{$filter} = '';
            $this->resetPage();
        }
    }

    /**
     * The last 30 days, day by day in the viewer's time zone, one stack per brand.
     *
     * @param  Builder<Lead>  $query
     * @param  list<Brand>  $brands
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>, brands: list<array{brand: Brand, total: int, color: string}>, total: int}
     */
    private function perDay(Builder $query, array $brands, User $viewer): array
    {
        $first = ViewerTime::now($viewer)->startOfDay()->subDays(self::DAYS - 1);
        $days = [];

        for ($day = $first, $i = 0; $i < self::DAYS; $i++, $day = $day->addDay()) {
            $days[$day->toDateString()] = $day;
        }

        $counts = [];

        foreach ($query->where('created_at', '>=', $first->setTimezone((string) config('app.timezone')))->toBase()->get(['brand_id', 'created_at']) as $row) {
            $date = ViewerTime::local(CarbonImmutable::parse((string) $row->created_at, (string) config('app.timezone')), $viewer)->toDateString();
            $counts[(int) $row->brand_id][$date] = ($counts[(int) $row->brand_id][$date] ?? 0) + 1;
        }

        $used = [];
        $rows = [];
        $datasets = [];

        foreach ($brands as $brand) {
            $color = mb_strtoupper((string) $brand->primaryColor());

            if ($color === '' || in_array($color, $used, true)) {
                $color = collect(self::COLORS)->first(fn (string $spare): bool => ! in_array($spare, $used, true)) ?? '#78716C';
            }

            $used[] = $color;
            $data = array_map(fn (string $date): int => $counts[(int) $brand->getKey()][$date] ?? 0, array_keys($days));
            $rows[] = ['brand' => $brand, 'total' => array_sum($data), 'color' => $color];
            $datasets[] = ['label' => $brand->name, 'data' => $data, 'backgroundColor' => $color, 'borderRadius' => 3, 'maxBarThickness' => 44, 'stack' => 'leads'];
        }

        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'labels' => array_values(array_map(fn (CarbonImmutable $day): string => $day->format('j M'), $days)),
            'datasets' => $datasets,
            'brands' => $rows,
            'total' => array_sum(array_column($rows, 'total')),
        ];
    }

    /**
     * The websites that sent the most leads in the last 30 days.
     *
     * @param  Builder<Lead>  $query
     * @param  array<int, string>  $names
     * @return list<array{site: int, name: string, total: int}>
     */
    private function bySite(Builder $query, array $names): array
    {
        return $query->where('created_at', '>=', now()->subDays(self::DAYS))
            ->whereNotNull('source_id')
            ->selectRaw('source_id, count(*) as total')->groupBy('source_id')->orderByDesc('total')->limit(8)
            ->toBase()->get()
            ->map(fn (object $row): array => ['site' => (int) $row->source_id, 'name' => $names[(int) $row->source_id] ?? 'A removed website', 'total' => (int) $row->total])
            ->values()->all();
    }

    private function viewer(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
