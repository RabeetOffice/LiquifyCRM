<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\Countries;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Core\Support\Timezones;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Actions\ArchiveLead;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\MergeLeads;
use App\Modules\Leads\Actions\RevealLeadContact;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Actions\SendLeadToDepartment;
use App\Modules\Leads\Exceptions\LeadIsWon;
use App\Modules\Leads\Exceptions\RevealLimitReached;
use App\Modules\Leads\Livewire\Concerns\EditsLeadDetails;
use App\Modules\Leads\Livewire\Concerns\TakesLeads;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Leads\Support\FollowUpZones;
use App\Modules\Leads\Support\LeadEnquiry;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Leads\Support\ProfileNames;
use App\Modules\Meta\Jobs\LearnProfileName;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Actions\StoreClientFile;
use App\Modules\Sales\Livewire\Concerns\ChoosesSaleCurrency;
use App\Modules\Sales\Livewire\Concerns\CreatesInvoices;
use App\Modules\Sales\Livewire\Concerns\RecordsPayments;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\CareTeams;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sales\Support\Money;
use App\Modules\Sales\Support\SalePayments;
use App\Modules\Sites\Support\LeadSites;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
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
    use ChoosesSaleCurrency;
    use CreatesInvoices;
    use EditsLeadDetails;
    use RecordsPayments;
    use TakesLeads;
    use WithConfirmation;
    use WithFileUploads;

    #[Locked]
    public int $leadId;

    /** True on the lead's own page, where there is room for two columns. */
    #[Locked]
    public bool $page = false;

    /** Notes, follow-ups, the chat and the history: the enquiry itself is always in view above them. */
    public string $tab = 'notes';

    public bool $showSend = false;

    public string $sendTo = '';

    public bool $showStatus = false;

    public string $statusTo = '';

    public string $note = '';

    public bool $showFollowUp = false;

    public string $followUpAt = '';

    public string $followUpTitle = 'Follow up';

    /** The time zone the follow-up's time is in: the viewer's own, or the client's ("5 PM UK time"). */
    public string $followUpZone = '';

    public bool $showWon = false;

    public string $wonAmount = '';

    /** The currency the sale was made in: the brand's unless the client paid in another. */
    public string $wonCurrency = '';

    public string $wonService = '';

    public string $wonClosedAt = '';

    /** What the client paid with the sale, when it is less than the deal (empty: all of it). */
    public string $wonPaid = '';

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

    /** A person chosen in the assign menu (the Assign tile opens it): one click gives them the lead. */
    public function quickAssign(int $userId): void
    {
        $lead = $this->lead();
        $this->authorize('assign', $lead);

        $agent = app(AssignableAgents::class)->find($this->user(), $this->brand(), $userId, $lead->department_id);

        if ($agent === null) {
            $this->toastWarning('Not assigned', 'Choose a person from the list.');

            return;
        }

        $previous = $lead->assigned_to;

        app(AssignLead::class)->handle($lead, $agent, $this->user());

        $this->changed();
        $this->toast('Assigned to '.$agent->name, null, 'success', $this->undoPayload($lead, 'assign', $previous));
    }

    /**
     * "Unassign" in the same menu (user request, 26 Oct 2026): an Admin or the
     * lead's Manager takes it off its owner, back to its team's queue.
     */
    public function unassign(): void
    {
        $lead = $this->lead();
        $this->authorize('assign', $lead);

        $previous = $lead->assigned_to;

        if ($previous === null) {
            return;
        }

        app(AssignLead::class)->handle($lead, null, $this->user());

        $this->changed();
        $this->toast('Unassigned', 'Back in the queue: nobody owns it now.', 'success', $this->undoPayload($lead, 'assign', $previous));
    }

    /** A team chosen in the same menu, by whoever may send leads to departments. */
    public function quickSend(int $departmentId): void
    {
        $this->sendTo = (string) $departmentId;
        $this->sendToDepartment();
    }

    /** "Same person as …": ask before two leads become one. */
    public function confirmMerge(int $otherId): void
    {
        $other = $this->twin($otherId);

        $this->askConfirmation('merge', [$otherId], 'Merge the two leads of '.$other->name.'?', 'The '.$other->source_type->getLabel().' lead goes into this one: its chat, notes, follow-ups and history move here, and this lead takes whatever it was missing. The other lead is removed. This cannot be undone.', 'Merge into one lead');
    }

    public function merge(int $otherId): void
    {
        $other = $this->twin($otherId);

        try {
            app(MergeLeads::class)->handle($this->lead(), $other, $this->user());
        } catch (InvalidArgumentException $exception) {
            $this->toastWarning('Not merged', $exception->getMessage());

            return;
        }

        $this->changed();
        $this->toastSuccess('Merged into one lead', 'Everything of the other lead is here now.');
    }

    /** A lead this one may be merged with: by somebody who may merge both (LeadPolicy::merge). */
    private function twin(int $otherId): Lead
    {
        $lead = $this->lead();
        $other = Lead::query()->visibleTo($this->user())->findOrFail($otherId);

        abort_unless($this->user()->can('merge', $lead) && $this->user()->can('merge', $other), 403);

        return $other;
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
        abort_unless(array_key_exists((int) $this->sendTo, $this->departmentOptions()), 403);

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

        if ($lead->isLockedInWon()) {
            $this->toastWarning('This lead stays in Won', LeadIsWon::withSale()->getMessage());

            return;
        }

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

        try {
            app(ChangeLeadStatus::class)->handle($lead, $status, $this->user());
        } catch (LeadIsWon $exception) {
            $this->toastWarning('This lead stays in Won', $exception->getMessage());

            return;
        }

        $this->changed();
        $this->toast('Status: '.$status->name, null, 'success', $this->undoPayload($lead, 'status', $previous));
    }

    public function openWon(): void
    {
        $lead = $this->lead();
        $this->authorize('recordSale', $lead);

        $this->wonAmount = '';
        $this->wonCurrency = $this->brand()->currency;
        $this->dealRate = null;
        $this->dealRateMissing = false;
        $this->wonPaid = '';
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
            'wonPaid' => ['nullable', 'numeric', 'min:0', 'lte:wonAmount'],
            'wonService' => ['nullable', 'string', 'max:190'],
            'wonClosedAt' => ['required', 'date', 'before_or_equal:today'],
            'wonContract' => ['nullable', ...StoreClientFile::rules()],
            'wonHandTo' => ['nullable', 'string'],
            'wonCurrency' => ['nullable', 'string', 'size:3'],
        ], ['wonContract.max' => 'The file may be up to '.(ClientFile::MAX_KB / 1024).' MB.'], ['wonAmount' => 'deal amount', 'wonPaid' => 'paid now', 'wonService' => 'service or package', 'wonClosedAt' => 'close date', 'wonContract' => 'contract']);

        $team = filled($data['wonHandTo'] ?? null) ? CareTeams::parse($this->brand(), (string) $data['wonHandTo']) : null;

        if (filled($data['wonHandTo'] ?? null) && $team === null) {
            $this->addError('wonHandTo', 'Choose a team or a person from the list.');

            return;
        }

        try {
            $sale = app(RecordSale::class)->handle($lead, (float) $data['wonAmount'], $data['wonService'], CarbonImmutable::parse($data['wonClosedAt'])->setTimeFrom(now()), $this->user(), filled($data['wonPaid'] ?? null) ? (float) $data['wonPaid'] : null, $data['wonCurrency'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $this->addError('wonAmount', $exception->getMessage());

            return;
        }

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
        $this->toastSuccess('Sale recorded: '.$sale->label(), $sale->due() > 0.0 ? Money::format($sale->dealDue(), $sale->dealCurrency()).' still to collect: record it with Record payment when it comes. '.$told : $told);
    }

    public function updatedWonCurrency(): void
    {
        $this->lookUpDealRate($this->wonCurrency, $this->brand()->currency);
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

    /** "Add note" and the N key: the note is written on the Notes tab itself, not in a dialog. */
    public function openNote(): void
    {
        $this->authorize('update', $this->lead());
        $this->tab = 'notes';
        $this->dispatch('focus-note');
    }

    public function addNote(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $this->validate(['note' => ['required', 'string', 'max:5000']], ['note.required' => 'Write the note first.']);

        app(AddLeadNote::class)->handle($lead, $this->note, $this->user());

        $this->note = '';
        $this->tab = 'notes';
        $this->changed();
        $this->toastSuccess('Note added');
    }

    public function openFollowUp(): void
    {
        $this->authorize('update', $this->lead());
        // Tomorrow at 10:00 where this person is: the form is in their own time zone.
        $this->followUpAt = ViewerTime::now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');
        $this->followUpTitle = 'Follow up';
        $this->followUpZone = ViewerTime::zone();
        $this->showFollowUp = true;
    }

    public function scheduleFollowUp(): void
    {
        $lead = $this->lead();
        $this->authorize('update', $lead);
        $this->validate(['followUpAt' => ['required', 'date'], 'followUpTitle' => ['required', 'string', 'max:160']]);

        $own = ViewerTime::zone();
        $zone = FollowUpZones::ready() && ViewerTime::valid($this->followUpZone) ? $this->followUpZone : $own;
        $dueAt = ViewerTime::parseIn($this->followUpAt, $zone);

        if ($dueAt === null) {
            $this->addError('followUpAt', 'Choose a date and time.');

            return;
        }

        $task = app(ScheduleFollowUp::class)->handle($lead, $dueAt, $this->followUpTitle, $this->user(), timezone: $zone !== $own ? $zone : null);

        $this->showFollowUp = false;
        $this->changed();
        $this->toastSuccess('Follow-up set', ($there = $task->theirTime()) !== null ? $there.', '.ViewerTime::local($dueAt)->format('g:i A').' your time.' : null);
    }

    /**
     * The time zones offered first for a follow-up: the viewer's, the brand's, where the lead's phone is dialled,
     * then the usual ones. The rest is searched (the picker fetches the whole list).
     *
     * @return list<array{value: string, label: string, hint: string, search: string}>
     */
    private function followUpZoneChoices(Lead $lead): array
    {
        return Timezones::only(array_values(array_filter([
            ViewerTime::zone(),
            $this->brand()->timezone,
            Countries::timezone(Countries::fromPhone($lead->phone)),
            ...Timezones::COMMON,
        ], fn (?string $zone): bool => ViewerTime::valid($zone))));
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

    /** Out of the working lists, nothing deleted: Undo on the message, or Bring back later. */
    public function archive(): void
    {
        $lead = $this->lead();
        $this->authorize('archive', $lead);

        app(ArchiveLead::class)->handle($lead, $this->user());

        $this->changed();
        $this->toast($lead->name.' archived', 'It is out of the lists. It comes back by itself if they get in touch again.', 'success', $this->undoPayload($lead, 'archive', null));
    }

    public function bringBack(): void
    {
        $lead = $this->lead();
        $this->authorize('archive', $lead);

        app(ArchiveLead::class)->bringBack($lead, $this->user());

        $this->changed();
        $this->toastSuccess($lead->name.' is back in the lists');
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

        $this->toastSuccess('Lead moved to the bin', 'Restore it from Bin, at the top of Leads.');
        $this->dispatch('lead-deleted', id: $lead->getKey());
    }

    // ---------------------------------------------------------------- render

    public function render(): View
    {
        // Deleted here a moment ago, or deleted or merged by somebody else while it was open: say so, not "Not found".
        if (! Lead::query()->whereKey($this->leadId)->exists()) {
            $binned = $this->user()->can('restoreAny', Lead::class) ? Lead::query()->inBin()->whereKey($this->leadId)->first() : null;

            return view('leads::livewire.app.lead-panel-gone', [
                'binUrl' => $binned !== null ? route('brand.leads.bin', ['brand' => $this->brand()->slug, 'search' => $binned->name]) : null,
            ]);
        }

        $lead = $this->lead()->load(['status', 'department', 'assignee', 'notes.author', 'tasks']);
        $user = $this->user();
        $canSeeFull = app(ContactVisibility::class)->canSeeFull($user, $lead);

        LeadSites::attach([$lead]);

        $statuses = LeadStatus::query()->ordered()->get();
        $canUpdate = $user->can('update', $lead);

        // On a colleague's lead only the sales this person may see anyway: front sales does not see each other's money.
        $sales = Sale::query()->where('lead_id', $lead->getKey())->when(! $canUpdate, fn ($query) => $query->visibleTo($user))->with(['closer', ...(SalePayments::ready() ? ['payments.recorder'] : [])])->latest('closed_at')->get();

        // The client this lead became: where they are now, and the files front sales added for them.
        $client = $sales->isNotEmpty() ? $this->client() : null;
        $client?->loadMissing(['department', 'owner']);
        $canRecordSale = $user->can('recordSale', $lead);
        $canAssign = $user->can('assign', $lead);
        $canDistribute = $user->can('distribute', $lead);

        $timeline = Activity::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->getKey())
            ->with('causer')
            ->latest('id')
            ->limit(80)
            ->get();

        // The lead's chat (Messenger or Instagram), its latest if there are two.
        $chat = Conversation::query()->where('lead_id', $lead->getKey())->latest('last_message_at')->first(['id', 'channel']);

        // The name on their Facebook or Instagram profile, asked for once in a while until Meta gives it.
        LearnProfileName::soon($lead);

        return view('leads::livewire.app.lead-panel', [
            'lead' => $lead,
            'followUpZones' => $this->showFollowUp && FollowUpZones::ready() ? $this->followUpZoneChoices($lead) : null,
            'canSeeFull' => $canSeeFull,
            'timeline' => $timeline,
            'openTasks' => $lead->tasks->whereNull('done_at'),
            'sales' => $sales,
            'client' => $client,
            // Only the files this person may open: a lead given to somebody else after the sale does not bring the seller's contract with it.
            'clientFiles' => $client !== null ? ClientFile::query()->ofLead($lead)->latest('id')->get()->filter(fn (ClientFile $file): bool => $user->can('viewFile', [$client, $file]))->values() : new Collection,
            'teams' => $canRecordSale || $client !== null ? CareTeams::options($this->brand()) : [],
            'fileCategories' => ClientFile::CATEGORIES,
            'maxMb' => ClientFile::MAX_KB / 1024,
            'careReady' => ClientCare::ready(),
            'brandCurrency' => $this->brand()->currency,
            'currencyOptions' => $this->showWon ? $this->dealCurrencyOptions($this->brand()->currency) : [],
            'paymentsReady' => SalePayments::ready(),
            'conversationId' => $chat?->getKey(),
            'chatChannel' => $chat?->channelLabel() ?? 'Messenger',
            'statuses' => $statuses,
            'path' => $this->path($statuses, $lead),
            'answers' => LeadEnquiry::answers($lead),
            'copyText' => LeadEnquiry::copyText($lead, $canSeeFull),
            'sourceFacts' => LeadEnquiry::source($lead),
            'inboxUrl' => LeadEnquiry::inboxUrl($lead),
            'profileName' => ProfileNames::shown($lead),
            // Other leads of the same person, offered for merging to whoever may merge both; a colleague's is only named.
            'twins' => $user->can('merge', $lead) ? array_map(fn (array $twin): array => [...$twin, 'mergeable' => $user->can('merge', $twin['lead'])], LeadMatcher::twins($lead, $user)) : [],
            'people' => $canAssign ? app(AssignableAgents::class)->people($user, $this->brand()) : [],
            'teamChoices' => $canDistribute ? $this->teamChoices() : [],
            'departmentOptions' => $this->departmentOptions(),
            // Won with a sale: the pipeline path stands still (the sale is corrected on the client's page instead).
            'wonLocked' => $lead->status->is_won && ($sales->isNotEmpty() || $lead->hasSale()),
            'archived' => $lead->isArchived() ? ['by' => $lead->archiver?->name, 'at' => $lead->archived_at] : null,
            'can' => [
                'take' => $user->can('take', $lead),
                'archive' => $user->can('archive', $lead),
                'assign' => $canAssign,
                'distribute' => $canDistribute,
                'update' => $canUpdate,
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

    protected function paymentRecorded(): void
    {
        $this->changed();
    }

    protected function invoiceCreated(): void
    {
        $this->changed();
    }

    private function changed(): void
    {
        $this->dispatch('lead-updated', id: $this->leadId);
    }

    /**
     * The departments a lead can be sent to from the assign menu: the ones that work leads.
     *
     * @return list<array{id: int, name: string, color: string}>
     */
    private function teamChoices(): array
    {
        return Department::query()
            ->orderBy('name')
            ->get()
            ->reject(fn (Department $department): bool => $department->isUpsell())
            ->map(fn (Department $department): array => ['id' => (int) $department->getKey(), 'name' => $department->name, 'color' => $department->color ?? '#FF6B2C'])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function departmentOptions(): array
    {
        $user = $this->user();
        $query = Department::query()->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereKey($user->managedDepartmentIds($this->brand()));
        }

        // Upsell teams look after clients: leads are sent to sales departments only.
        return $query->get()->reject(fn (Department $department): bool => $department->isUpsell())->pluck('name', 'id')->all();
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
