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
use App\Modules\Leads\Livewire\Concerns\EditsLeadDetails;
use App\Modules\Leads\Livewire\Concerns\HandlesLeadUndo;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Actions\StoreClientFile;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\CareTeams;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sales\Support\Money;
use App\Modules\Sites\Support\LeadSites;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One lead: who it is, where it stands on the pipeline path, the actions
 * from the permission table, contact and details, then timeline, message,
 * notes and follow-ups in tabs. Shown as a slide-over from the list and the
 * pipeline, and as a full page (two columns) from notification links. Once
 * the lead is won, front sales finishes here: the contract goes into the
 * client's storage and the client is handed to an Upsell team.
 */
final class LeadPanel extends Component
{
    use AuthorizesRequests;
    use EditsLeadDetails;
    use HandlesLeadUndo;
    use WithConfirmation;
    use WithFileUploads;

    #[Locked]
    public int $leadId;

    /** True on the lead's own page, where there is room for two columns. */
    #[Locked]
    public bool $page = false;

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

    public bool $showWon = false;

    public string $wonAmount = '';

    public string $wonService = '';

    public string $wonClosedAt = '';

    /** The signed contract or the invoice, added with the sale. */
    public mixed $wonContract = null;

    /** Where the new client goes: "12" an Upsell team, "12:34" a person in it, empty for later. */
    public string $wonHandTo = '';

    public bool $showHandOver = false;

    public string $handTo = '';

    public bool $showClientFile = false;

    /** @var array<int, mixed> */
    public array $clientUploads = [];

    public string $clientFileCategory = 'contract';

    public function mount(int $leadId, bool $page = false): void
    {
        $this->leadId = $leadId;
        $this->page = $page;
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

        $this->applyStatus($lead, LeadStatus::query()->findOrFail((int) $this->statusTo));
    }

    /** A click on a step of the pipeline path. */
    public function moveTo(int $statusId): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);

        $this->applyStatus($lead, LeadStatus::query()->findOrFail($statusId));
    }

    private function applyStatus(Lead $lead, LeadStatus $status): void
    {
        $previous = $lead->status_id;
        $this->showStatus = false;

        if ($previous === $status->getKey()) {
            return;
        }

        // Won always goes through the sale sheet, so every won lead has an amount.
        if ($status->is_won) {
            $this->openWon();

            return;
        }

        app(ChangeLeadStatus::class)->handle($lead, $status, $this->user());

        $this->changed();
        $this->toast('Status: '.$status->name, null, 'success', $this->undoPayload($lead, 'status', $previous));
    }

    public function openWon(): void
    {
        $lead = $this->lead();
        $this->authorize('recordSale', $lead);

        $this->wonAmount = '';
        $this->wonService = (string) ($lead->fields['service'] ?? '');
        $this->wonClosedAt = now()->toDateString();
        $this->wonContract = null;
        $this->wonHandTo = '';
        $this->resetErrorBag();
        $this->showWon = true;
    }

    public function recordSale(): void
    {
        $lead = $this->lead();
        $this->authorize('recordSale', $lead);

        $data = $this->validate([
            'wonAmount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'wonService' => ['nullable', 'string', 'max:190'],
            'wonClosedAt' => ['required', 'date', 'before_or_equal:today'],
            'wonContract' => ['nullable', ...StoreClientFile::rules()],
            'wonHandTo' => ['nullable', 'string'],
        ], ['wonContract.max' => 'The file may be up to '.(ClientFile::MAX_KB / 1024).' MB.'], ['wonAmount' => 'amount', 'wonService' => 'service or package', 'wonClosedAt' => 'close date', 'wonContract' => 'contract']);

        $team = filled($data['wonHandTo'] ?? null) ? CareTeams::parse($this->brand(), (string) $data['wonHandTo']) : null;

        if (filled($data['wonHandTo'] ?? null) && $team === null) {
            $this->addError('wonHandTo', 'Choose a team or a person from the list.');

            return;
        }

        $sale = app(RecordSale::class)->handle($lead, (float) $data['wonAmount'], $data['wonService'], CarbonImmutable::parse($data['wonClosedAt'])->setTimeFrom(now()), $this->user());
        $client = ClientCare::ready() ? Client::query()->find($sale->client_id) : null;
        $told = 'It counts towards this month\'s targets.';

        if ($client !== null && $this->wonContract !== null) {
            app(StoreClientFile::class)->handle($client, $this->wonContract, 'contract', $this->user(), $lead);
        }

        // Front sales ends here: the client goes to the Upsell team in the same step.
        if ($client !== null && $team !== null && ! $client->isHandedOver() && $this->user()->can('handOver', $client)) {
            app(HandOverClient::class)->handle($client, $team[0], $team[1], $this->user());
            $told = 'The client is with '.($client->owner()->value('name') ?? $team[0]->name).' now.';
        }

        $this->wonContract = null;
        $this->showWon = false;
        $this->changed();
        $this->toastSuccess('Sale recorded: '.Money::format($sale->amount, $sale->currency), $told);
    }

    /** After the sale: send the client to an Upsell team (or a person in it). */
    public function openHandOver(): void
    {
        $client = $this->client();
        abort_if($client === null, 404);
        $this->authorize('handOver', $client);

        $this->handTo = '';
        $this->resetErrorBag();
        $this->showHandOver = true;
    }

    public function handOverClient(): void
    {
        $client = $this->client();
        abort_if($client === null, 404);
        $this->authorize('handOver', $client);
        $this->validate(['handTo' => ['required', 'string']], ['handTo.required' => 'Choose a team or a person.']);

        $team = CareTeams::parse($this->brand(), $this->handTo);

        if ($team === null) {
            $this->addError('handTo', 'Choose a team or a person from the list.');

            return;
        }

        try {
            app(HandOverClient::class)->handle($client, $team[0], $team[1], $this->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('handTo', $exception->getMessage());

            return;
        }

        $this->showHandOver = false;
        $this->changed();
        $this->toastSuccess('Client handed to '.$team[0]->name, ($client->owner()->value('name') ?? 'The team').' takes it from here.');
    }

    /** The contract, an invoice, anything front sales has for the client: it goes into the client's storage. */
    public function openClientFile(): void
    {
        $client = $this->client();
        abort_if($client === null, 404);
        $this->authorize('addFile', $client);

        $this->clientUploads = [];
        $this->clientFileCategory = 'contract';
        $this->resetErrorBag();
        $this->showClientFile = true;
    }

    public function saveClientFiles(StoreClientFile $action): void
    {
        $client = $this->client();
        abort_if($client === null, 404);
        $this->authorize('addFile', $client);

        $this->validate([
            'clientUploads' => ['required', 'array', 'min:1', 'max:10'],
            'clientUploads.*' => StoreClientFile::rules(),
            'clientFileCategory' => ['required', 'in:'.implode(',', array_keys(ClientFile::CATEGORIES))],
        ], [
            'clientUploads.required' => 'Choose at least one file.',
            'clientUploads.max' => 'Add up to 10 files at a time.',
            'clientUploads.*.max' => 'A file may be up to '.(ClientFile::MAX_KB / 1024).' MB.',
        ], ['clientUploads.*' => 'file']);

        $lead = $this->lead();

        foreach ($this->clientUploads as $upload) {
            $action->handle($client, $upload, $this->clientFileCategory, $this->user(), $lead);
        }

        $count = count($this->clientUploads);
        $this->clientUploads = [];
        $this->showClientFile = false;
        $this->toastSuccess($count === 1 ? 'File added to the client' : $count.' files added to the client');
    }

    public function confirmRemoveClientFile(int $fileId): void
    {
        $this->askConfirmation('removeClientFile', [$fileId], 'Remove this file?', 'It is deleted from the client\'s files and cannot be brought back.', 'Remove file', 'danger');
    }

    public function removeClientFile(int $fileId): void
    {
        $client = $this->client();
        abort_if($client === null, 404);

        $file = ClientFile::query()->ofLead($this->leadId)->findOrFail($fileId);
        $this->authorize('deleteFile', [$client, $file]);

        app(StoreClientFile::class)->delete($client, $file, $this->user());

        $this->toastSuccess('File removed');
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

        LeadSites::attach([$lead]);

        $statuses = LeadStatus::query()->ordered()->get();
        $sales = Sale::query()->where('lead_id', $lead->getKey())->with('closer')->latest('closed_at')->get();

        // The client this lead became: where they are now, and the files front sales added for them.
        $client = $sales->isNotEmpty() ? $this->client() : null;
        $client?->loadMissing(['department', 'owner']);
        $canRecordSale = $user->can('recordSale', $lead);

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
            'sales' => $sales,
            'client' => $client,
            'clientFiles' => $client !== null ? ClientFile::query()->ofLead($lead)->latest('id')->get() : new Collection,
            'teams' => $canRecordSale || $client !== null ? CareTeams::options($this->brand()) : [],
            'fileCategories' => ClientFile::CATEGORIES,
            'maxMb' => ClientFile::MAX_KB / 1024,
            'careReady' => ClientCare::ready(),
            'brandCurrency' => $this->brand()->currency,
            'conversationId' => Conversation::query()->where('lead_id', $lead->getKey())->latest('last_message_at')->value('id'),
            'statuses' => $statuses,
            'path' => $this->path($statuses, $lead),
            'agentOptions' => $this->agentOptions($lead->department_id),
            'departmentOptions' => $this->departmentOptions(),
            'can' => [
                'assign' => $user->can('assign', $lead),
                'distribute' => $user->can('distribute', $lead),
                'update' => $user->can('update', $lead),
                'recordSale' => $canRecordSale,
                'handOver' => $client !== null && ! $client->isHandedOver() && ! $client->isClosed() && $user->can('handOver', $client),
                'addFile' => $client !== null && $user->can('addFile', $client),
                'openClient' => $client !== null && $user->can('view', $client),
                'reveal' => ! $canSeeFull && $user->can('reveal', $lead),
                'delete' => $user->can('delete', $lead),
            ],
        ]);
    }

    /**
     * The pipeline as steps: the ones behind the lead are done, its own is current.
     * A lost lead has nothing behind it, so only Lost is lit.
     *
     * @param  Collection<int, LeadStatus>  $statuses
     * @return list<array{status: LeadStatus, state: string}>
     */
    private function path(Collection $statuses, Lead $lead): array
    {
        $current = $statuses->search(fn (LeadStatus $status): bool => $status->getKey() === $lead->status_id);
        $lost = $lead->status->is_lost;

        return $statuses->values()->map(fn (LeadStatus $status, int $index): array => [
            'status' => $status,
            'state' => match (true) {
                $index === $current => 'current',
                ! $lost && ! $status->is_lost && $current !== false && $index < $current => 'done',
                default => 'todo',
            },
        ])->all();
    }

    private function lead(): Lead
    {
        return Lead::query()->findOrFail($this->leadId);
    }

    /** The client this lead belongs to, once the update that brought hand-over and storage is in. */
    private function client(): ?Client
    {
        $clientId = ClientCare::ready() ? Lead::query()->whereKey($this->leadId)->value('client_id') : null;

        return $clientId !== null ? Client::query()->find($clientId) : null;
    }

    protected function detailsLead(): Lead
    {
        return $this->lead();
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
