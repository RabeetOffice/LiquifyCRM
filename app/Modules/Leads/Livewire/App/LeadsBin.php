<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\DeleteLeadForever;
use App\Modules\Leads\Actions\RestoreLead;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Support\LeadSites;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The bin: the leads somebody deleted in this brand, newest first, with who
 * deleted them and when. Restore brings one back, or every ticked one, as it
 * was; Delete forever (password asked again) removes it for good, and the bin
 * does that by itself after DeleteLeadForever::KEEP_DAYS. A lead that was
 * merged into another is not here: its notes, chat and timeline moved to the
 * lead that stayed, so bringing it back would only make an empty twin. Only
 * Admins delete leads, so only Admins see the bin.
 */
#[Layout('components.layouts.app')]
#[Title('Bin')]
final class LeadsBin extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithTableState;
    use WithToasts;

    private const FOREVER = 'The lead, its notes, follow-ups, chat and history are removed and cannot be brought back. A sale it had stays in the numbers and with its client.';

    public Brand $brand;

    /** @var list<int> */
    public array $selected = [];

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('restoreAny', Lead::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function restore(int $leadId): void
    {
        $lead = $this->binned()->findOrFail($leadId);
        $this->authorize('restore', $lead);

        $owned = $lead->assigned_to !== null || $lead->department_id !== null;

        app(RestoreLead::class)->handle($lead, $this->user());

        $this->selected = array_values(array_diff($this->selected, [$leadId]));

        $this->toastSuccess(
            $lead->name.' is back in Leads',
            $owned && $lead->assigned_to === null && $lead->department_id === null
                ? 'Its team or owner is no longer here, so it waits in the Unassigned queue.'
                : null,
        );
    }

    public function restoreSelected(): void
    {
        $this->authorize('restoreAny', Lead::class);

        $done = 0;

        foreach ($this->binned()->whereKey($this->selected)->get() as $lead) {
            if ($this->user()->can('restore', $lead)) {
                app(RestoreLead::class)->handle($lead, $this->user());
                $done++;
            }
        }

        $this->selected = [];
        $this->toastSuccess($done.' '.str('lead')->plural($done).' back in Leads');
    }

    public function confirmDeleteForever(int $leadId): void
    {
        $lead = $this->binned()->findOrFail($leadId);
        $this->authorize('forceDelete', $lead);

        $this->askConfirmation('deleteForever', [$leadId], 'Delete '.$lead->name.' forever?', self::FOREVER, 'Delete forever', 'danger', password: true);
    }

    public function deleteForever(int $leadId): void
    {
        $this->ensureConfirmed();

        $lead = $this->binned()->findOrFail($leadId);
        $this->authorize('forceDelete', $lead);

        app(DeleteLeadForever::class)->handle($lead, $this->user());

        $this->selected = array_values(array_diff($this->selected, [$leadId]));
        $this->toastSuccess('Lead deleted for good');
    }

    public function confirmDeleteSelected(): void
    {
        $this->authorize('forceDeleteAny', Lead::class);

        // The ticked leads are fixed now: the dialog deletes these, whatever is ticked meanwhile.
        $ids = $this->binned()->whereKey($this->selected)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $count = count($ids);

        if ($count === 0) {
            return;
        }

        $this->askConfirmation('deleteSelectedForever', [$ids], 'Delete '.$count.' '.str('lead')->plural($count).' forever?', self::FOREVER, 'Delete forever', 'danger', password: true);
    }

    /** @param  list<int>  $ids */
    public function deleteSelectedForever(array $ids): void
    {
        $this->ensureConfirmed();
        $this->authorize('forceDeleteAny', Lead::class);

        $done = 0;

        foreach ($this->binned()->whereKey($ids)->get() as $lead) {
            if ($this->user()->can('forceDelete', $lead)) {
                app(DeleteLeadForever::class)->handle($lead, $this->user());
                $done++;
            }
        }

        $this->selected = [];
        $this->toastSuccess($done.' '.str('lead')->plural($done).' deleted for good');
    }

    public function render(): View
    {
        $like = '%'.trim($this->search).'%';

        $leads = $this->binned()
            ->with([
                'status',
                'department',
                'assignee',
                // Who deleted it: the last "deleted" line on its timeline.
                'activities' => fn ($query) => $query->where('event', 'leads.deleted')->with('causer'),
            ])
            ->when(trim($this->search) !== '', fn (Builder $query): Builder => $query->where(fn (Builder $where): Builder => $where
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)))
            ->orderBy($this->sortColumn(['name', 'deleted_at', 'created_at'], 'deleted_at'), $this->sortDirection('desc'))
            ->orderByDesc('id')
            ->paginate($this->perPage);

        LeadSites::attach($leads->getCollection());

        // A team removed since: the lead goes back to the queue when it is restored.
        $removedTeams = Department::onlyTrashed()->forBrand($this->brand)->whereKey($leads->pluck('department_id')->filter()->unique()->all())->pluck('name', 'id')->all();

        $total = $this->binned()->count();
        $oldest = $total > 0 ? CarbonImmutable::parse((string) $this->binned()->min('deleted_at')) : null;

        return view('leads::livewire.app.leads-bin', [
            'leads' => $leads,
            'removedTeams' => $removedTeams,
            'keepDays' => DeleteLeadForever::KEEP_DAYS,
            'daysLeft' => $leads->getCollection()->mapWithKeys(fn (Lead $lead): array => [$lead->getKey() => $this->daysLeft($lead->deleted_at)])->all(),
            'canDelete' => $this->user()->can('forceDeleteAny', Lead::class),
            'summary' => [
                'total' => $total,
                'week' => $total > 0 ? $this->binned()->where('deleted_at', '>=', now()->subDays(7))->count() : 0,
                'next' => $oldest !== null ? $this->daysLeft($oldest) : null,
            ],
        ]);
    }

    /** Whole days until the bin deletes a lead by itself (0: tonight). */
    private function daysLeft(?CarbonInterface $deletedAt): int
    {
        return $deletedAt === null ? DeleteLeadForever::KEEP_DAYS : max(0, (int) ceil(now()->diffInDays($deletedAt->copy()->addDays(DeleteLeadForever::KEEP_DAYS), false)));
    }

    /**
     * @return Builder<Lead>
     */
    private function binned(): Builder
    {
        return Lead::query()->forBrand($this->brand)->inBin();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
