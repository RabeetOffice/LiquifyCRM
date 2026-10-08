<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\RevealLeadContact;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Actions\SendLeadToDepartment;
use App\Modules\Leads\Exceptions\RevealLimitReached;
use App\Modules\Leads\Livewire\Concerns\HandlesLeadUndo;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Meta\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One lead: the five essentials first (name, contact, status, owner, next
 * follow-up), then timeline, message and notes in tabs, with every action
 * from the permission table. Shown as a slide-over from the list and as a
 * full page from notification links.
 */
final class LeadPanel extends Component
{
    use AuthorizesRequests;
    use HandlesLeadUndo;
    use WithConfirmation;

    #[Locked]
    public int $leadId;

    public string $tab = 'timeline';

    public bool $showAssign = false;

    public string $assignTo = '';

    public bool $showSend = false;

    public string $sendTo = '';

    public bool $showStatus = false;

    public string $statusTo = '';

    public bool $showNote = false;

    public string $note = '';

    public bool $showFollowUp = false;

    public string $followUpAt = '';

    public string $followUpTitle = 'Follow up';

    public function mount(int $leadId): void
    {
        $this->leadId = $leadId;
        $this->authorize('view', $this->lead());
    }

    // --------------------------------------------------------------- actions

    public function openAssign(): void
    {
        $lead = $this->lead();
        $this->authorize('assign', $lead);
        $this->assignTo = (string) ($lead->assigned_to ?? '');
        $this->showAssign = true;
    }

    public function assign(): void
    {
        $lead = $this->lead();
        $this->authorize('assign', $lead);
        $this->validate(['assignTo' => ['required', 'integer']]);

        $agent = User::query()->findOrFail((int) $this->assignTo);
        $previous = $lead->assigned_to;

        app(AssignLead::class)->handle($lead, $agent, $this->user());

        $this->showAssign = false;
        $this->changed();
        $this->toast('Assigned to '.$agent->name, null, 'success', $this->undoPayload($lead, 'assign', $previous));
    }

    public function openSend(): void
    {
        $lead = $this->lead();
        $this->authorize('distribute', $lead);
        $this->sendTo = (string) ($lead->department_id ?? '');
        $this->showSend = true;
    }

    public function sendToDepartment(): void
    {
        $lead = $this->lead();
        $this->authorize('distribute', $lead);
        $this->validate(['sendTo' => ['required', 'integer']]);

        $department = Department::query()->findOrFail((int) $this->sendTo);

        app(SendLeadToDepartment::class)->handle($lead, $department, $this->user());

        $this->showSend = false;
        $this->changed();
        $this->toastSuccess('Sent to '.$department->name, 'Its Managers have been told.');
    }

    public function openStatus(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $this->statusTo = (string) $lead->status_id;
        $this->showStatus = true;
    }

    public function changeStatus(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $this->validate(['statusTo' => ['required', 'integer']]);

        $status = LeadStatus::query()->findOrFail((int) $this->statusTo);
        $previous = $lead->status_id;

        app(ChangeLeadStatus::class)->handle($lead, $status, $this->user());

        $this->showStatus = false;
        $this->changed();
        $this->toast('Status: '.$status->name, null, 'success', $this->undoPayload($lead, 'status', $previous));
    }

    public function openNote(): void
    {
        $this->authorize('update', $this->lead());
        $this->note = '';
        $this->showNote = true;
    }

    public function addNote(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $this->validate(['note' => ['required', 'string', 'max:5000']]);

        app(AddLeadNote::class)->handle($lead, $this->note, $this->user());

        $this->showNote = false;
        $this->note = '';
        $this->tab = 'notes';
        $this->changed();
        $this->toastSuccess('Note added');
    }

    public function openFollowUp(): void
    {
        $this->authorize('update', $this->lead());
        $this->followUpAt = CarbonImmutable::tomorrow()->setTime(10, 0)->format('Y-m-d\TH:i');
        $this->followUpTitle = 'Follow up';
        $this->showFollowUp = true;
    }

    public function scheduleFollowUp(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $this->validate(['followUpAt' => ['required', 'date'], 'followUpTitle' => ['required', 'string', 'max:160']]);

        app(ScheduleFollowUp::class)->handle($lead, CarbonImmutable::parse($this->followUpAt), $this->followUpTitle, $this->user());

        $this->showFollowUp = false;
        $this->changed();
        $this->toastSuccess('Follow-up set');
    }

    public function completeTask(int $taskId): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);

        $task = $lead->tasks()->findOrFail($taskId);

        app(ScheduleFollowUp::class)->complete($task, $this->user());

        $this->changed();
        $this->toastSuccess('Follow-up done');
    }

    public function reveal(): void
    {
        $lead = $this->lead();
        $this->authorize('reveal', $lead);

        try {
            app(RevealLeadContact::class)->handle($lead, $this->user());
        } catch (RevealLimitReached $exception) {
            $this->toastWarning('Reveal limit reached', $exception->getMessage());

            return;
        }

        $this->toastSuccess('Contact details revealed', 'This reveal is recorded in the activity log.');
    }

    public function confirmDelete(): void
    {
        $lead = $this->lead();
        $this->authorize('delete', $lead);

        $this->askConfirmation('delete', [], 'Delete '.$lead->name.'?', 'The lead is moved to the bin and can be restored by an Admin.', 'Delete', 'danger');
    }

    public function delete(): void
    {
        $lead = $this->lead();
        $this->authorize('delete', $lead);

        $lead->delete();

        Audit::log('leads.deleted', $lead, [], 'Lead deleted', logName: 'leads');

        $this->toastSuccess('Lead deleted');
        $this->dispatch('lead-deleted', id: $lead->getKey());
    }

    // ---------------------------------------------------------------- render

    public function render(): View
    {
        $lead = $this->lead()->load(['status', 'department', 'assignee', 'notes.author', 'tasks']);
        $user = $this->user();
        $canSeeFull = app(ContactVisibility::class)->canSeeFull($user, $lead);

        $timeline = Activity::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->getKey())
            ->with('causer')
            ->latest('id')
            ->limit(80)
            ->get();

        return view('leads::livewire.app.lead-panel', [
            'lead' => $lead,
            'canSeeFull' => $canSeeFull,
            'timeline' => $timeline,
            'openTasks' => $lead->tasks->whereNull('done_at'),
            'conversationId' => Conversation::query()->where('lead_id', $lead->getKey())->latest('last_message_at')->value('id'),
            'statuses' => LeadStatus::query()->ordered()->get(),
            'agentOptions' => $this->agentOptions($lead->department_id),
            'departmentOptions' => $this->departmentOptions(),
            'can' => [
                'assign' => $user->can('assign', $lead),
                'distribute' => $user->can('distribute', $lead),
                'update' => $user->can('update', $lead),
                'reveal' => ! $canSeeFull && $user->can('reveal', $lead),
                'delete' => $user->can('delete', $lead),
            ],
        ]);
    }

    private function lead(): Lead
    {
        return Lead::query()->findOrFail($this->leadId);
    }

    private function changed(): void
    {
        $this->dispatch('lead-updated', id: $this->leadId);
    }

    /** @return array<int, string> */
    private function agentOptions(?int $departmentId): array
    {
        $user = $this->user();
        $brand = $this->brand();

        $query = User::query()
            ->whereHas('memberships', fn (Builder $query): Builder => $query->acrossBrands()->where('brand_id', $brand->getKey())->where('role', BrandRole::Agent->value)->where('status', 'active'))
            ->orderBy('name');

        $departmentIds = $departmentId !== null
            ? [$departmentId]
            : ($user->isGlobalAdmin() ? null : $user->managedDepartmentIds($brand));

        if ($departmentIds !== null) {
            $query->whereHas('departments', fn (Builder $query): Builder => $query->acrossBrands()->whereIn('departments.id', $departmentIds));
        }

        return $query->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private function departmentOptions(): array
    {
        $user = $this->user();
        $query = Department::query()->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereKey($user->managedDepartmentIds($this->brand()));
        }

        return $query->pluck('name', 'id')->all();
    }

    private function brand(): Brand
    {
        /** @var Brand $brand */
        $brand = app(CurrentBrand::class)->get();

        return $brand;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
