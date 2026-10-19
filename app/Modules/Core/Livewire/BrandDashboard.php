<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Core\Support\SetupChecklist;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\Concerns\TakesLeads;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Models\Task;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Sales\Support\CareTeams;
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
    use TakesLeads;

    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;

        // This dashboard is about leads. Someone who works from clients alone starts on their clients.
        $user = auth()->user();

        if ($user instanceof User && CareTeams::worksClientsOnly($user, $brand)) {
            $this->redirectRoute('brand.clients.index', ['brand' => $brand->slug], navigate: true);
        }
    }

    /** One-click assign from the queue. */
    public function assign(int $leadId, int $agentId): void
    {
        $lead = Lead::query()->findOrFail($leadId);
        $this->authorize('assign', $lead);

        // The same rule as the assign menu: somebody of the lead's department, never somebody who only works clients.
        $agent = app(AssignableAgents::class)->find($this->user(), $this->brand, $agentId, $lead->department_id) ?? abort(403);
        $previous = $lead->assigned_to;

        app(AssignLead::class)->handle($lead, $agent, $this->user());

        $this->toast('Assigned to '.$agent->name, $lead->name, 'success', $this->undoPayload($lead, 'assign', $previous));
    }

    public function render(): View
    {
        $user = $this->user();
        $agent = ! $user->isGlobalAdmin() && $user->isAgentIn($this->brand);
        // Front sales: the team's free leads are listed here to take.
        $team = $agent && $user->frontDepartmentIds($this->brand) !== [];
        $visible = fn (): Builder => Lead::query()->visibleTo($user);
        // The numbers are of the leads a person works: an Agent's stay their own, though they see the team's.
        $worked = fn (): Builder => Lead::query()->workedBy($user);

        // The counts and chart numbers: cached per brand for 60 seconds, per person
        // for anyone who does not see the whole brand.
        $stats = DashboardCache::remember((int) $this->brand->getKey(), $user->isGlobalAdmin() ? 'all' : 'user-'.$user->getKey(), function () use ($visible, $worked, $user, $agent, $team): array {
            $today = now()->startOfDay();

            return [
                'today' => $worked()->where('created_at', '>=', $today)->count(),
                'yesterday' => $worked()->whereBetween('created_at', [$today->copy()->subDay(), $today])->count(),
                'week' => $worked()->where('created_at', '>=', now()->subDays(7))->count(),
                'previousWeek' => $worked()->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count(),
                'unassigned' => $agent && ! $team ? null : $visible()->unassigned()->count(),
                'myOpen' => $agent ? $worked()->open()->count() : null,
                'overdue' => Task::query()->visibleTo($user)->overdue()->count(),
                'total' => $worked()->count(),
                // By status id, so the count is answered from the index.
                'won' => $worked()->whereIn('status_id', LeadStatus::query()->where('is_won', true)->pluck('id'))->count(),
                'byDay' => $worked()
                    ->where('created_at', '>=', now()->subDays(29)->startOfDay())
                    ->selectRaw('date(created_at) as day, count(*) as total')
                    ->groupBy('day')
                    ->orderBy('day')
                    ->pluck('total', 'day')
                    ->all(),
                'bySource' => $worked()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->groupBy('source_type')
                    ->toBase()
                    ->pluck(DB::raw('count(*) as total'), 'source_type')
                    ->all(),
                'byStatus' => $worked()->groupBy('status_id')->pluck(DB::raw('count(*) as total'), 'status_id')->all(),
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

        $queue = $agent && ! $team
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
            'team' => $team,
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
        return app(AssignableAgents::class)->for($this->user(), $this->brand);
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
