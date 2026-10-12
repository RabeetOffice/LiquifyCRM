<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Core\Support\SetupChecklist;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\Concerns\HandlesLeadUndo;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Models\Task;
use App\Modules\Sites\Support\LeadSites;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Brand dashboard: the New / Unassigned queue first (count badge, oldest
 * first, one-click assign), cards for new leads today / 7 days, unassigned,
 * overdue follow-ups and conversion, leads by day, source and status, and
 * the latest activity. Agents see their own numbers.
 */
#[Layout('components.layouts.app')]
#[Title('Dashboard')]
final class BrandDashboard extends Component
{
    use HandlesLeadUndo;

    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
    }

    /** One-click assign from the queue. */
    public function assign(int $leadId, int $agentId): void
    {
        $lead = Lead::query()->findOrFail($leadId);
        $this->authorize('assign', $lead);

        $agent = User::query()->findOrFail($agentId);
        $previous = $lead->assigned_to;

        app(AssignLead::class)->handle($lead, $agent, $this->user());

        $this->toast('Assigned to '.$agent->name, $lead->name, 'success', $this->undoPayload($lead, 'assign', $previous));
    }

    public function render(): View
    {
        $user = $this->user();
        $agent = ! $user->isGlobalAdmin() && $user->isAgentIn($this->brand);
        $visible = fn (): Builder => Lead::query()->visibleTo($user);

        // The counts and chart numbers: cached per brand for 60 seconds, per person
        // for anyone who does not see the whole brand.
        $stats = DashboardCache::remember((int) $this->brand->getKey(), $user->isGlobalAdmin() ? 'all' : 'user-'.$user->getKey(), function () use ($visible, $user, $agent): array {
            $today = now()->startOfDay();

            return [
                'today' => $visible()->where('created_at', '>=', $today)->count(),
                'yesterday' => $visible()->whereBetween('created_at', [$today->copy()->subDay(), $today])->count(),
                'week' => $visible()->where('created_at', '>=', now()->subDays(7))->count(),
                'previousWeek' => $visible()->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count(),
                'unassigned' => $agent ? null : $visible()->unassigned()->count(),
                'myOpen' => $agent ? $visible()->open()->count() : null,
                'overdue' => Task::query()->visibleTo($user)->overdue()->count(),
                'total' => $visible()->count(),
                // By status id, so the count is answered from the index.
                'won' => $visible()->whereIn('status_id', LeadStatus::query()->where('is_won', true)->pluck('id'))->count(),
                'byDay' => $visible()
                    ->where('created_at', '>=', now()->subDays(29)->startOfDay())
                    ->selectRaw('date(created_at) as day, count(*) as total')
                    ->groupBy('day')
                    ->orderBy('day')
                    ->pluck('total', 'day')
                    ->all(),
                'bySource' => $visible()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->groupBy('source_type')
                    ->toBase()
                    ->pluck(DB::raw('count(*) as total'), 'source_type')
                    ->all(),
                'byStatus' => $visible()->groupBy('status_id')->pluck(DB::raw('count(*) as total'), 'status_id')->all(),
            ];
        });

        $todayCount = $stats['today'];
        $yesterdayCount = $stats['yesterday'];
        $weekCount = $stats['week'];
        $previousWeekCount = $stats['previousWeek'];
        $unassigned = $stats['unassigned'];
        $myOpen = $stats['myOpen'];
        $overdue = $stats['overdue'];
        $total = $stats['total'];
        $won = $stats['won'];
        $byDay = $stats['byDay'];
        $bySource = collect($stats['bySource']);
        $byStatus = $stats['byStatus'];

        $queue = $agent
            ? collect()
            : $visible()->queue()->with(['status', 'department'])->limit(8)->get();

        LeadSites::attach($queue);

        $followUps = $agent
            ? Task::query()->visibleTo($user)->open()->where('due_at', '<=', now()->endOfDay())->with('lead')->orderBy('due_at')->limit(8)->get()
            : collect();

        $days = collect(range(29, 0))->map(fn (int $i): string => now()->subDays($i)->toDateString());

        $statuses = LeadStatus::query()->ordered()->get();

        $agents = $agent ? collect() : $this->agentOptions();

        // The audit trail is for Admins only; Managers and Agents get no "Recent activity".
        $activity = $user->isGlobalAdmin()
            ? Activity::query()->forBrand($this->brand)->with('causer')->latest('id')->limit(8)->get()
            : null;

        return view('core::livewire.brand-dashboard', [
            'user' => $user,
            'agent' => $agent,
            'checklist' => $user->isGlobalAdmin() ? SetupChecklist::for($this->brand) : null,
            'todayCount' => $todayCount,
            'todayDelta' => $this->delta($todayCount, $yesterdayCount),
            'weekCount' => $weekCount,
            'weekDelta' => $this->delta($weekCount, $previousWeekCount),
            'unassigned' => $unassigned,
            'myOpen' => $myOpen,
            'overdue' => $overdue,
            'conversion' => $total > 0 ? (int) round($won / $total * 100) : null,
            'won' => $won,
            'total' => $total,
            'queue' => $queue,
            'followUps' => $followUps,
            'agents' => $agents,
            'chartDays' => [
                'labels' => $days->map(fn (string $day): string => now()->parse($day)->format('j M'))->all(),
                'values' => $days->map(fn (string $day): int => (int) ($byDay[$day] ?? 0))->all(),
            ],
            'chartSource' => [
                'labels' => $bySource->keys()->map(fn (int|string $source): string => LeadSource::tryFrom((string) $source)?->getLabel() ?? (string) $source)->all(),
                'values' => $bySource->values()->map(fn ($value): int => (int) $value)->all(),
            ],
            'chartStatus' => [
                'labels' => $statuses->pluck('name')->all(),
                'values' => $statuses->map(fn (LeadStatus $status): int => (int) ($byStatus[$status->getKey()] ?? 0))->all(),
                'colors' => $statuses->pluck('color')->all(),
            ],
            'activity' => $activity,
        ]);
    }

    /** @return array<int, string> */
    private function agentOptions(): array
    {
        $user = $this->user();

        $query = User::query()
            ->whereHas('memberships', fn (Builder $query): Builder => $query->acrossBrands()->where('brand_id', $this->brand->getKey())->where('role', BrandRole::Agent->value)->where('status', 'active'))
            ->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereHas('departments', fn (Builder $query): Builder => $query->acrossBrands()->whereIn('departments.id', $user->managedDepartmentIds($this->brand)));
        }

        return $query->pluck('name', 'id')->all();
    }

    /** @return array{text: string, up: ?bool}|null */
    private function delta(int $current, int $previous): ?array
    {
        if ($previous === 0 && $current === 0) {
            return null;
        }

        if ($previous === 0) {
            return ['text' => '+'.$current, 'up' => true];
        }

        $change = (int) round(($current - $previous) / $previous * 100);

        return ['text' => ($change >= 0 ? '+' : '').$change.'%', 'up' => $change >= 0];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
