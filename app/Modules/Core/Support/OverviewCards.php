<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sites\Enums\CheckStatus;
use App\Modules\Sites\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The cards under "Brands this month" on the admin Overview, each answering
 * one question across brands: how fast leads hear back, where they come
 * from, which chats wait for a reply, which websites went quiet, who sells
 * most, when leads arrive and which follow-ups are due. Money stays in each
 * brand's own currency. One grouped query per card.
 */
final class OverviewCards
{
    /** Days the first reply, source and busiest-hour cards look back over. */
    public const DAYS = 30;

    /** A lead counts as answered quickly when its first contact came within this many minutes. */
    public const QUICK_MINUTES = 60;

    /**
     * @param  Collection<int, Brand>  $brands  keyed by id
     */
    public function __construct(
        private readonly Collection $brands,
        private readonly CarbonImmutable $now,
    ) {}

    /**
     * How fast each brand gets back to a new lead: of the leads that arrived
     * in the last DAYS days (from a website, Facebook or a chat; not imported
     * or added by hand), the median time to the first contact and the share
     * contacted within QUICK_MINUTES. Leads younger than that are left out of
     * the share, as they still have time. Fastest first.
     *
     * @return list<array{brand: Brand, leads: int, contacted: int, median: ?int, quick: ?int}>
     */
    public function replySpeed(): array
    {
        $rows = Lead::query()->acrossBrands()->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->whereNotIn('source_type', [LeadSource::Import->value, LeadSource::Manual->value])
            ->where('created_at', '>=', $this->now->subDays(self::DAYS))
            ->get(['brand_id', 'created_at', 'first_contacted_at']);

        $speed = [];

        foreach ($rows->groupBy('brand_id') as $brandId => $leads) {
            $brand = $this->brands->get((int) $brandId);

            if (! $brand instanceof Brand) {
                continue;
            }

            $minutes = [];
            $settled = 0;
            $quick = 0;

            foreach ($leads as $lead) {
                $arrived = CarbonImmutable::parse((string) $lead->created_at);
                $wait = $lead->first_contacted_at !== null ? max(0, (int) $arrived->diffInMinutes(CarbonImmutable::parse((string) $lead->first_contacted_at))) : null;

                if ($wait !== null) {
                    $minutes[] = $wait;
                }

                // A lead that arrived less than an hour ago and is not contacted yet still has time.
                if ($wait !== null || $arrived->lte($this->now->subMinutes(self::QUICK_MINUTES))) {
                    $settled++;
                    $quick += $wait !== null && $wait <= self::QUICK_MINUTES ? 1 : 0;
                }
            }

            $speed[] = [
                'brand' => $brand,
                'leads' => $leads->count(),
                'contacted' => count($minutes),
                'median' => $this->median($minutes),
                'quick' => $settled > 0 ? (int) round($quick / $settled * 100) : null,
            ];
        }

        usort($speed, fn (array $a, array $b): int => [$a['median'] === null, $a['median']] <=> [$b['median'] === null, $b['median']]);

        return $speed;
    }

    /**
     * Where each brand's leads came from in the last DAYS days, busiest brand
     * first, with the sources in a fixed order.
     *
     * @return array{brands: list<array{brand: Brand, total: int, sources: array<string, int>}>, sources: list<LeadSource>}
     */
    public function sources(): array
    {
        $rows = Lead::query()->acrossBrands()->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->where('created_at', '>=', $this->now->subDays(self::DAYS))
            ->groupBy('brand_id', 'source_type')
            ->selectRaw('brand_id, source_type, COUNT(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->brand_id][(string) $row->source_type] = (int) $row->total;
        }

        $used = array_values(array_filter(LeadSource::cases(), fn (LeadSource $source): bool => collect($counts)->contains(fn (array $sources): bool => isset($sources[$source->value]))));

        $brands = collect($counts)
            ->map(fn (array $sources, int $id): array => [
                'brand' => $this->brands->get($id),
                'total' => array_sum($sources),
                'sources' => collect($used)->mapWithKeys(fn (LeadSource $source): array => [$source->value => $sources[$source->value] ?? 0])->filter()->all(),
            ])
            ->filter(fn (array $row): bool => $row['brand'] instanceof Brand)
            ->sortByDesc('total')
            ->values()
            ->all();

        return ['brands' => $brands, 'sources' => $used];
    }

    /**
     * Messenger and Instagram chats whose last message is the person's and
     * that can still be answered (Meta allows a reply for REPLY_WINDOW_HOURS),
     * per brand, the longest wait first.
     *
     * @return list<array{brand: Brand, chats: int, since: CarbonImmutable, channels: list<string>}>
     */
    public function chatsWaiting(): array
    {
        $rows = Conversation::query()->acrossBrands()->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->where('last_inbound_at', '>', $this->now->subHours(Conversation::REPLY_WINDOW_HOURS))
            ->where(fn ($query) => $query->whereNull('last_message_at')->orWhereColumn('last_inbound_at', '>=', 'last_message_at'))
            ->groupBy('brand_id', 'channel')
            ->selectRaw('brand_id, channel, COUNT(*) as chats, MIN(last_inbound_at) as since')
            ->get();

        $waiting = [];

        foreach ($rows as $row) {
            $id = (int) $row->brand_id;
            $brand = $this->brands->get($id);

            if (! $brand instanceof Brand) {
                continue;
            }

            $since = CarbonImmutable::parse((string) $row->since);
            $waiting[$id] ??= ['brand' => $brand, 'chats' => 0, 'since' => $since, 'channels' => []];
            $waiting[$id]['chats'] += (int) $row->chats;
            $waiting[$id]['since'] = $since->lt($waiting[$id]['since']) ? $since : $waiting[$id]['since'];
            $waiting[$id]['channels'][] = (string) $row->channel;
        }

        $waiting = array_values($waiting);
        usort($waiting, fn (array $a, array $b): int => $a['since'] <=> $b['since']);

        return $waiting;
    }

    /**
     * Websites that are on but went quiet: no lead for the quiet limit (48
     * hours), none at all two days after they were added, or a failed check.
     *
     * @return list<array{site: Site, brand: Brand, reason: string, since: ?CarbonImmutable, failed: bool}>
     */
    public function quietSites(): array
    {
        $quietSince = $this->now->subHours((int) config('liquify.sites.quiet_hours', 48));

        $sites = Site::query()->acrossBrands()
            ->whereIn('brand_id', $this->brands->keys())
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('last_lead_at', '<', $quietSince)
                ->orWhere(fn ($never) => $never->whereNull('last_lead_at')->where('created_at', '<', $quietSince))
                ->orWhere('health_status', CheckStatus::Fail->value))
            ->orderByRaw('COALESCE(last_lead_at, created_at)')
            ->get();

        $quiet = [];

        foreach ($sites as $site) {
            $brand = $this->brands->get((int) $site->brand_id);

            if (! $brand instanceof Brand) {
                continue;
            }

            $failed = $site->health_status === CheckStatus::Fail;

            $quiet[] = [
                'site' => $site,
                'brand' => $brand,
                'reason' => match (true) {
                    $site->last_lead_at !== null && $site->last_lead_at->lt($quietSince) => 'Last lead '.$site->last_lead_at->diffForHumans(),
                    $site->last_lead_at === null && $site->created_at?->lt($quietSince) => 'No lead since it was added',
                    default => 'A check failed',
                },
                'since' => $site->last_lead_at,
                'failed' => $failed,
            ];
        }

        return $quiet;
    }

    /**
     * Who sold most this month: the number of sales and their total, per
     * person and brand (a person who sells in two brands has two lines, as
     * money is never added up across currencies).
     *
     * @return list<array{user: User, brand: Brand, sales: int, revenue: float}>
     */
    public function topSellers(int $limit = 5): array
    {
        $start = $this->now->startOfMonth();

        $rows = Sale::query()->acrossBrands()->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->whereNotNull('user_id')
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $start->addMonth())
            ->groupBy('user_id', 'brand_id')
            ->selectRaw('user_id, brand_id, COUNT(*) as sales, COALESCE(SUM(amount), 0) as revenue')
            ->orderByDesc('sales')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $users = User::withTrashed()->whereKey($rows->pluck('user_id')->unique()->all())->get()->keyBy('id');
        $sellers = [];

        foreach ($rows as $row) {
            $user = $users->get((int) $row->user_id);
            $brand = $this->brands->get((int) $row->brand_id);

            if ($user instanceof User && $brand instanceof Brand) {
                $sellers[] = ['user' => $user, 'brand' => $brand, 'sales' => (int) $row->sales, 'revenue' => (float) $row->revenue];
            }
        }

        return $sellers;
    }

    /**
     * When leads arrive: the last DAYS days of every brand, by day of the week
     * (Monday first) and hour, in the viewer's time zone. Counted in quarter
     * hours in UTC and placed here, like the Leads per day chart.
     *
     * @return array{grid: list<list<int>>, max: int, total: int}
     */
    public function busiestHours(string $timezone): array
    {
        $quarter = "CONCAT(DATE_FORMAT(created_at, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(created_at) / 15) * 15, 2, '0'))";

        $rows = Lead::query()->acrossBrands()->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->where('created_at', '>=', $this->now->subDays(self::DAYS))
            ->groupByRaw($quarter)
            ->selectRaw($quarter.' as slot, COUNT(*) as total')
            ->get();

        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($rows as $row) {
            $local = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $row->slot, 'UTC');

            if ($local !== null) {
                $local = $local->setTimezone($timezone);
                $grid[(int) $local->format('N') - 1][(int) $local->format('G')] += (int) $row->total;
            }
        }

        $flat = array_merge(...$grid);

        return ['grid' => $grid, 'max' => max($flat), 'total' => array_sum($flat)];
    }

    /**
     * Follow-ups due today and overdue, per brand, the most overdue first. The
     * same days as the Follow-ups screen, which a click opens.
     *
     * @return list<array{brand: Brand, today: int, overdue: int}>
     */
    public function followUps(): array
    {
        $live = Lead::query()->acrossBrands()->select('id');
        $count = fn ($tasks): Collection => $tasks->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->whereIn('lead_id', $live)
            ->groupBy('brand_id')
            ->selectRaw('brand_id, COUNT(*) as total')
            ->pluck('total', 'brand_id');

        $today = $count(Task::query()->acrossBrands()->dueToday());
        $overdue = $count(Task::query()->acrossBrands()->overdue());

        $rows = [];

        foreach ($today->keys()->merge($overdue->keys())->unique() as $brandId) {
            $brand = $this->brands->get((int) $brandId);

            if ($brand instanceof Brand) {
                $rows[] = ['brand' => $brand, 'today' => (int) ($today[$brandId] ?? 0), 'overdue' => (int) ($overdue[$brandId] ?? 0)];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['overdue'], $b['today']] <=> [$a['overdue'], $a['today']]);

        return $rows;
    }

    /** "14 min", "2 h 10 min", "3 days": a wait in words. */
    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => $minutes.' min',
            $minutes < 1440 => intdiv($minutes, 60).' h'.($minutes % 60 > 0 ? ' '.($minutes % 60).' min' : ''),
            default => ($days = (int) round($minutes / 1440)).' '.str('day')->plural($days),
        };
    }

    /** @param  list<int>  $values */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
