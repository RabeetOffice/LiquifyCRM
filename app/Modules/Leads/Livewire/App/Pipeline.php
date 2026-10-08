<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Livewire\Concerns\HandlesLeadUndo;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Kanban pipeline: drag leads between statuses; same visibility rules as the list. */
#[Layout('components.layouts.app')]
#[Title('Pipeline')]
final class Pipeline extends Component
{
    use AuthorizesRequests;
    use HandlesLeadUndo;

    public Brand $brand;

    #[Url(except: '')]
    public string $search = '';

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
            $this->toastWarning('Record the sale first', 'Open '.$lead->name.' and click Mark as Won to enter the amount.');

            return;
        }

        $previous = $lead->status_id;

        app(ChangeLeadStatus::class)->handle($lead, $status, $this->user());

        $this->toast($lead->name.' → '.$status->name, null, 'success', $this->undoPayload($lead, 'status', $previous));
    }

    public function render(): View
    {
        $like = '%'.$this->search.'%';
        $statuses = LeadStatus::query()->ordered()->get();

        $leads = Lead::query()
            ->visibleTo($this->user())
            ->when($this->search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $w): Builder => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->with(['assignee', 'department'])
            ->orderByDesc('last_activity_at')
            ->limit(400)
            ->get()
            ->groupBy('status_id');

        return view('leads::livewire.app.pipeline', [
            'statuses' => $statuses,
            'columns' => $statuses->mapWithKeys(fn (LeadStatus $status): array => [$status->getKey() => $leads->get($status->getKey(), collect())]),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
