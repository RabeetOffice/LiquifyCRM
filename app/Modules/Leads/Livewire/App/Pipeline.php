<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Livewire\Concerns\HandlesLeadUndo;
use App\Modules\Leads\Livewire\Concerns\PreviewsLead;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\Money;
use App\Modules\Sites\Support\LeadSites;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kanban pipeline: drag leads between statuses, open one in the slide-over,
 * narrow the board to one owner. Same visibility rules as the list.
 */
#[Layout('components.layouts.app')]
#[Title('Pipeline')]
final class Pipeline extends Component
{
    use AuthorizesRequests;
    use HandlesLeadUndo;
    use PreviewsLead;

    /** An open lead nobody has touched for this many days is marked quiet on its card. */
    public const QUIET_AFTER_DAYS = 7;

    public Brand $brand;

    #[Url(except: '')]
    public string $search = '';

    /** '' for everyone, 'none' for unassigned, or an Agent's id. */
    #[Url(except: '')]
    public string $owner = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Lead::class);
    }

    public function move(int $leadId, int $statusId): void
    {
        $lead = Lead::query()->findOrFail($leadId);
        $this->authorize('update', $lead);

        $status = LeadStatus::query()->findOrFail($statusId);

        if ($lead->status_id === $status->getKey()) {
            return;
        }

        if ($status->is_won) {
            $this->open = $lead->getKey();
            $this->toastWarning('Record the sale first', 'Click Mark as Won on '.$lead->name.' to enter the amount.');

            return;
        }

        $previous = $lead->status_id;

        app(ChangeLeadStatus::class)->handle($lead, $status, $this->user());

        $this->toast($lead->name.' → '.$status->name, null, 'success', $this->undoPayload($lead, 'status', $previous));
    }

    #[On('lead-updated')]
    public function refreshBoard(): void
    {
        // The board re-renders with the latest data.
    }

    #[On('lead-deleted')]
    public function leadDeleted(): void
    {
        $this->open = null;
    }

    public function render(): View
    {
        $user = $this->user();
        $agent = ! $user->isGlobalAdmin() && $user->isAgentIn($this->brand);
        $like = '%'.$this->search.'%';
        $statuses = LeadStatus::query()->ordered()->get();

        $leads = Lead::query()
            ->visibleTo($user)
            ->when($this->search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $w): Builder => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->when(! $agent && $this->owner === 'none', fn (Builder $q): Builder => $q->whereNull('assigned_to'))
            ->when(! $agent && ctype_digit($this->owner), fn (Builder $q): Builder => $q->where('assigned_to', (int) $this->owner))
            ->with(['assignee', 'department'])
            ->orderByDesc('last_activity_at')
            ->limit(400)
            ->get();

        LeadSites::attach($leads);

        $closed = $statuses->filter(fn (LeadStatus $status): bool => $status->is_won || $status->is_lost)->modelKeys();
        $open = $leads->reject(fn (Lead $lead): bool => in_array($lead->status_id, $closed, true));
        $quietBefore = now()->subDays(self::QUIET_AFTER_DAYS);
        $won = $leads->whereIn('status_id', $statuses->where('is_won', true)->modelKeys());
        $wonTotal = $won->isEmpty() ? 0.0 : (float) Sale::query()->whereIn('lead_id', $won->modelKeys())->sum('amount');
        $columns = $leads->groupBy('status_id');

        return view('leads::livewire.app.pipeline', [
            'statuses' => $statuses,
            'columns' => $statuses->mapWithKeys(fn (LeadStatus $status): array => [$status->getKey() => $columns->get($status->getKey(), collect())]),
            'agent' => $agent,
            'agentOptions' => $agent ? [] : app(AssignableAgents::class)->for($user, $this->brand),
            'summary' => [
                'open' => $open->count(),
                'unassigned' => $open->whereNull('assigned_to')->count(),
                'overdue' => $open->filter(fn (Lead $lead): bool => (bool) $lead->next_follow_up_at?->isPast())->count(),
            ],
            'quiet' => $open->filter(fn (Lead $lead): bool => $lead->last_activity_at !== null && $lead->last_activity_at->lt($quietBefore))->values()->modelKeys(),
            'wonTotal' => $wonTotal > 0 ? Money::format($wonTotal, $this->brand->currency) : null,
            'previewLead' => $this->previewLead($user),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
