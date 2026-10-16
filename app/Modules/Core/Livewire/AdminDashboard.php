<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\BrandsOverview;
use App\Modules\Sales\Support\Progress;
use App\Modules\System\Support\SystemUpdater;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin overview: what is worth comparing across brands. This month's leads
 * and sales, what waits for somebody (unassigned leads, overdue follow-ups),
 * leads per day of every brand (today, 7 or 30 days), every brand side by
 * side in its own currency, the latest activity, and the installed version
 * at the bottom.
 */
#[Layout('components.layouts.app')]
#[Title('Overview')]
final class AdminDashboard extends Component
{
    /** For a brand without a colour worth showing, or one that looks like a brand already on the chart. */
    private const SPARE_COLORS = ['#6366F1', '#14B8A6', '#F59E0B', '#EC4899', '#0EA5E9', '#84CC16', '#A855F7', '#EF4444', '#10B981', '#64748B'];

    /** One colour per lead source on "Where leads come from". */
    private const SOURCE_COLORS = ['website' => '#378ADD', 'facebook' => '#1D9E75', 'messenger' => '#7F77DD', 'instagram' => '#D4537E', 'manual' => '#888780', 'import' => '#BA7517', 'api' => '#639922'];

    /** "today", "7" or "30": the range of the Leads per day chart. */
    #[Url(except: '7')]
    public string $range = '7';

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $overview = BrandsOverview::make();
        $rows = collect($overview->rows());
        $timezone = $user->timezone ?: (string) config('app.timezone');

        $leads = (int) $rows->sum('leads');
        $sales = (int) $rows->sum('sales');
        $upsells = (int) $rows->sum('upsells');

        return view('core::livewire.admin-dashboard', [
            'user' => $user,
            'brands' => Brand::query()->where('is_active', true)->count(),
            'rows' => $rows->map(fn (array $row): array => [...$row, 'delta' => $this->delta($row['leads'], $row['previous'])])->all(),
            'leadsThisMonth' => $leads,
            'leadsDelta' => $this->delta($leads, (int) $rows->sum('previous')),
            'sales' => $sales,
            'salesSplit' => $upsells > 0 ? ($sales - $upsells).' front, '.$upsells.' '.str('upsell')->plural($upsells) : null,
            'unassigned' => (int) $rows->sum('unassigned'),
            'overdue' => (int) $rows->sum('overdue'),
            'update' => SystemUpdater::flag(),
            'perDay' => $this->perDay($overview, $user),
            'ranges' => BrandsOverview::RANGES,
            'cards' => $this->cards($overview, $rows->all(), $timezone),
            'sourceColors' => self::SOURCE_COLORS,
            'activity' => Activity::query()->with(['causer', 'brand'])->latest('id')->limit(8)->get(),
        ]);
    }

    /**
     * The chart's data: a stack of bars per day, one colour per brand (its
     * own, or a spare one when it has none or looks like another brand).
     *
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>, brands: list<array{brand: Brand, total: int, color: string}>, total: int, days: int, hourly: bool}
     */
    private function perDay(BrandsOverview $overview, User $user): array
    {
        $range = array_key_exists($this->range, BrandsOverview::RANGES) ? $this->range : '7';
        $data = $overview->leadsPerDay($range, $user->timezone ?: (string) config('app.timezone'));

        $used = [];
        $spare = self::SPARE_COLORS;
        $brands = [];
        $datasets = [];

        foreach ($data['brands'] as $row) {
            $color = mb_strtoupper((string) $row['brand']->primaryColor());

            if ($color === '' || in_array($color, $used, true)) {
                $spare = array_values(array_diff($spare, $used));
                $color = array_shift($spare) ?? '#78716C';
            }

            $used[] = $color;
            $brands[] = ['brand' => $row['brand'], 'total' => $row['total'], 'color' => $color];
            $datasets[] = ['label' => $row['brand']->name, 'data' => $row['counts'], 'backgroundColor' => $color, 'borderRadius' => 3, 'maxBarThickness' => 44, 'stack' => 'leads'];
        }

        return ['labels' => $data['labels'], 'datasets' => $datasets, 'brands' => $brands, 'total' => $data['total'], 'days' => $data['days'], 'hourly' => $range === 'today'];
    }

    /**
     * The eight cards under the brands table. Lead to sale comes from the
     * brand rows: this month's front sales against this month's leads, and
     * the month's money per lead in the brand's own currency.
     *
     * @param  list<array{brand: Brand, leads: int, previous: int, unassigned: int, overdue: int, sales: int, upsells: int, progress: Progress}>  $rows
     * @return array<string, mixed>
     */
    private function cards(BrandsOverview $overview, array $rows, string $timezone): array
    {
        $cards = $overview->cards();

        $conversion = collect($rows)
            ->filter(fn (array $row): bool => $row['leads'] > 0)
            ->map(fn (array $row): array => [
                'brand' => $row['brand'],
                'leads' => $row['leads'],
                'won' => $row['sales'] - $row['upsells'],
                'rate' => round(($row['sales'] - $row['upsells']) / $row['leads'] * 100, 1),
                'perLead' => round($row['progress']->revenue / $row['leads']),
                'currency' => $row['progress']->currency,
            ])
            ->sortByDesc('rate')
            ->values()
            ->all();

        return [
            'speed' => $cards->replySpeed(),
            'sources' => $cards->sources(),
            'conversion' => $conversion,
            'chats' => $cards->chatsWaiting(),
            'sites' => $cards->quietSites(),
            'sellers' => $cards->topSellers(),
            'hours' => $cards->busiestHours($timezone),
            'followUps' => $cards->followUps(),
        ];
    }

    /** @return array{text: string, up: ?bool}|null */
    private function delta(int $current, int $previous): ?array
    {
        if ($previous === 0 && $current === 0) {
            return null;
        }

        if ($previous === 0) {
            return ['text' => 'new', 'up' => true];
        }

        $change = (int) round(($current - $previous) / $previous * 100);

        return ['text' => ($change >= 0 ? '+' : '').$change.'%', 'up' => $change >= 0];
    }
}
