<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\AssignClient;
use App\Modules\Sales\Actions\CloseClient;
use App\Modules\Sales\Actions\CorrectSale;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordUpsell;
use App\Modules\Sales\Actions\SaveClientNote;
use App\Modules\Sales\Actions\StoreClientFile;
use App\Modules\Sales\Livewire\Concerns\ChoosesSaleCurrency;
use App\Modules\Sales\Livewire\Concerns\CreatesInvoices;
use App\Modules\Sales\Livewire\Concerns\RecordsPayments;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\ClientNote;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\CareTeams;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sales\Support\Money;
use App\Modules\Sales\Support\SalePayments;
use App\Modules\Sites\Support\LeadSites;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One client, from the first sale to the day they are closed: the story of
 * what they bought and from whom, who looks after them now, their files and
 * the text they shared. The Upsell side sees all of it and records upsells
 * here; a front Manager, before the hand-over, sees their own sales only.
 */
#[Layout('components.layouts.app')]
final class ClientShow extends Component
{
    use AuthorizesRequests;
    use ChoosesSaleCurrency;
    use CreatesInvoices;
    use RecordsPayments;
    use WithConfirmation;
    use WithFileUploads;
    use WithToasts;

    public Brand $brand;

    public Client $client;

    public string $tab = 'story';

    public function mount(Brand $brand, Client $client): void
    {
        $this->brand = $brand;
        $this->client = $client;
        $this->authorize('view', $client);
    }

    // ------------------------------------------------------ correcting a sale

    public ?int $editing = null;

    public bool $showEdit = false;

    public string $amount = '';

    /** The currency of the sale being corrected, as the client was charged. */
    public string $editCurrency = '';

    public string $service = '';

    public string $closedAt = '';

    /** Admins only: correct the amount, service or date of a recorded sale. */
    public function edit(int $saleId): void
    {
        $sale = $this->sale($saleId);
        $this->authorize('update', $sale);

        $this->editing = $saleId;
        // In the currency the client was charged in; a sale in another currency keeps its rate.
        $this->amount = (string) $sale->dealAmount();
        $this->editCurrency = $sale->dealCurrency();
        $this->service = (string) $sale->service;
        $this->closedAt = $sale->closed_at->toDateString();
        $this->resetErrorBag();
        $this->showEdit = true;
    }

    public function update(CorrectSale $action): void
    {
        $sale = $this->sale((int) $this->editing);
        $this->authorize('update', $sale);

        $data = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'service' => ['nullable', 'string', 'max:190'],
            'closedAt' => ['required', 'date', 'before_or_equal:today'],
        ], attributes: ['service' => 'service or package', 'closedAt' => 'close date']);

        $action->update($sale, (float) $data['amount'], $data['service'], CarbonImmutable::parse($data['closedAt'])->setTimeFrom($sale->closed_at), $this->user());

        $this->showEdit = false;
        $this->editing = null;
        $this->client->refresh();
        $this->toastSuccess('Sale corrected', 'Targets and lifetime value are counted again.');
    }

    public function confirmRemove(int $saleId): void
    {
        $sale = $this->sale($saleId);
        $this->authorize('delete', $sale);

        $this->askConfirmation('remove', [$saleId], 'Remove this sale of '.Money::format($sale->amount, $sale->currency).'?', $sale->isUpsell()
            ? 'It no longer counts towards any target or this client\'s lifetime value.'
            : 'It no longer counts towards any target or this client\'s lifetime value. If it was the lead\'s only sale, the lead is no longer Won.', 'Remove sale', 'danger', password: true);
    }

    public function remove(int $saleId): void
    {
        $this->ensureConfirmed();

        $sale = $this->sale($saleId);
        $this->authorize('delete', $sale);

        app(CorrectSale::class)->delete($sale, $this->user());

        $this->client->refresh();
        $this->toastSuccess('Sale removed');
    }

    // ----------------------------------------------------------------- upsell

    public bool $showUpsell = false;

    public string $upsellAmount = '';

    public string $upsellService = '';

    public string $upsellClosedAt = '';

    public string $upsellSeller = '';

    /** What the client paid with the upsell, when it is less than the deal (empty: all of it). */
    public string $upsellPaid = '';

    /** The currency the upsell was sold in: the brand's unless the client paid in another. */
    public string $upsellCurrency = '';

    public function openUpsell(): void
    {
        $this->authorize('recordUpsell', $this->client);

        $this->upsellAmount = '';
        $this->upsellCurrency = $this->brand->currency;
        $this->dealRate = null;
        $this->dealRateMissing = false;
        $this->upsellPaid = '';
        $this->upsellService = '';
        $this->upsellClosedAt = now()->toDateString();
        $this->upsellSeller = (string) $this->user()->getKey();
        $this->resetErrorBag();
        $this->showUpsell = true;
    }

    public function recordUpsell(RecordUpsell $action): void
    {
        $this->authorize('recordUpsell', $this->client);

        $data = $this->validate([
            'upsellAmount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'upsellPaid' => ['nullable', 'numeric', 'min:0', 'lte:upsellAmount'],
            'upsellService' => ['required', 'string', 'max:190'],
            'upsellClosedAt' => ['required', 'date', 'before_or_equal:today'],
            'upsellSeller' => ['nullable', 'integer'],
            'upsellCurrency' => ['nullable', 'string', 'size:3'],
        ], attributes: ['upsellAmount' => 'amount', 'upsellPaid' => 'paid now', 'upsellService' => 'what was sold', 'upsellClosedAt' => 'date', 'upsellSeller' => 'seller']);

        // An upsell goes under the name of whoever enters it; only a Manager or an Admin may enter it for a colleague.
        $seller = $this->user();

        if ((int) $data['upsellSeller'] !== (int) $seller->getKey() && array_key_exists((int) $data['upsellSeller'], $this->sellerOptions())) {
            $seller = User::query()->findOrFail((int) $data['upsellSeller']);
        }

        try {
            $sale = $action->handle($this->client, (float) $data['upsellAmount'], $data['upsellService'], CarbonImmutable::parse($data['upsellClosedAt'])->setTimeFrom(now()), $this->user(), $seller, filled($data['upsellPaid'] ?? null) ? (float) $data['upsellPaid'] : null, $data['upsellCurrency'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $this->addError('upsellAmount', $exception->getMessage());

            return;
        }

        $this->showUpsell = false;
        $this->tab = 'story';
        $this->client->refresh();
        $this->toastSuccess('Upsell recorded: '.$sale->label(), 'It is under '.($seller->is($this->user()) ? 'your name' : $seller->name).' and counts towards this month\'s targets.');
    }

    public function updatedUpsellCurrency(): void
    {
        $this->lookUpDealRate($this->upsellCurrency, $this->brand->currency);
    }

    // ------------------------------------------------ hand-over and the team

    public bool $showHandOver = false;

    public string $handTo = '';

    public string $helperId = '';

    public function openHandOver(): void
    {
        abort_unless($this->user()->can('handOver', $this->client) || $this->user()->can('assign', $this->client), 403);
        abort_if($this->client->isClosed(), 422, 'Reopen this client first.');

        $this->handTo = $this->client->department_id !== null
            ? $this->client->department_id.($this->client->owner_id !== null ? ':'.$this->client->owner_id : '')
            : '';
        $this->resetErrorBag();
        $this->showHandOver = true;
    }

    public function handOver(): void
    {
        $this->validate(['handTo' => ['required', 'string']], ['handTo.required' => 'Choose a team or a person.']);

        $choice = CareTeams::parse($this->brand, $this->handTo);

        if ($choice === null) {
            $this->addError('handTo', 'Choose a team or a person from the list.');

            return;
        }

        [$department, $person] = $choice;

        try {
            // The same team and a person named: only who looks after the client changes.
            if ($person !== null && $this->client->department_id === $department->getKey()) {
                $this->authorize('assign', $this->client);
                app(AssignClient::class)->handle($this->client, $person, $this->user());
            } else {
                $this->authorize('handOver', $this->client);
                app(HandOverClient::class)->handle($this->client, $department, $person, $this->user());
            }
        } catch (InvalidArgumentException $exception) {
            $this->addError('handTo', $exception->getMessage());

            return;
        }

        $this->showHandOver = false;
        $this->client->refresh();

        $owner = $this->client->owner_id !== null ? User::query()->find($this->client->owner_id) : null;

        // After handing over, front sales may no longer open the client.
        if (! $this->user()->can('view', $this->client)) {
            $this->toastSuccess('Client handed to '.$department->name, $owner !== null ? $owner->name.' looks after them from now on.' : null);
            $this->redirectRoute('brand.clients.index', ['brand' => $this->brand->slug], navigate: true);

            return;
        }

        $this->toastSuccess($owner !== null ? $owner->name.' looks after this client' : 'Handed to '.$department->name, $owner !== null ? 'They have been told.' : 'It waits there until somebody is chosen.');
    }

    public function addHelper(): void
    {
        $this->authorize('share', $this->client);
        $this->validate(['helperId' => ['required', 'integer']], attributes: ['helperId' => 'person']);

        try {
            app(AssignClient::class)->addHelper($this->client, User::query()->findOrFail((int) $this->helperId), $this->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('helperId', $exception->getMessage());

            return;
        }

        $this->helperId = '';
        $this->toastSuccess('Added to this client', 'They can open it and record their own upsells.');
    }

    public function removeHelper(int $userId): void
    {
        $this->authorize('share', $this->client);

        app(AssignClient::class)->removeHelper($this->client, User::query()->findOrFail($userId), $this->user());

        $this->toastSuccess('Removed from this client');
    }

    // ------------------------------------------------------------------ files

    public bool $showUpload = false;

    /** @var array<int, mixed> */
    public array $uploads = [];

    public string $uploadCategory = 'document';

    public function openUpload(): void
    {
        $this->authorize('addFile', $this->client);

        $this->uploads = [];
        $this->uploadCategory = 'document';
        $this->resetErrorBag();
        $this->showUpload = true;
    }

    public function saveFiles(StoreClientFile $action): void
    {
        $this->authorize('addFile', $this->client);

        $this->validate([
            'uploads' => ['required', 'array', 'min:1', 'max:10'],
            'uploads.*' => StoreClientFile::rules(),
            'uploadCategory' => ['required', 'in:'.implode(',', array_keys(ClientFile::CATEGORIES))],
        ], [
            'uploads.required' => 'Choose at least one file.',
            'uploads.max' => 'Add up to 10 files at a time.',
            'uploads.*.max' => 'A file may be up to '.(ClientFile::MAX_KB / 1024).' MB.',
        ], ['uploads.*' => 'file']);

        foreach ($this->uploads as $upload) {
            $action->handle($this->client, $upload, $this->uploadCategory, $this->user());
        }

        $count = count($this->uploads);
        $this->uploads = [];
        $this->showUpload = false;
        $this->tab = 'files';
        $this->toastSuccess($count === 1 ? 'File added' : $count.' files added');
    }

    public function confirmRemoveFile(int $fileId): void
    {
        $file = $this->file($fileId);
        $this->authorize('deleteFile', [$this->client, $file]);

        $this->askConfirmation('removeFile', [$fileId], 'Remove '.$file->original_name.'?', 'The file is deleted from storage and cannot be brought back.', 'Remove file', 'danger');
    }

    public function removeFile(int $fileId): void
    {
        $file = $this->file($fileId);
        $this->authorize('deleteFile', [$this->client, $file]);

        app(StoreClientFile::class)->delete($this->client, $file, $this->user());

        $this->toastSuccess('File removed');
    }

    // ------------------------------------------------------------- saved text

    public bool $showNote = false;

    public ?int $noteId = null;

    public string $noteTitle = '';

    public string $noteBody = '';

    public bool $noteSecret = false;

    /** @var list<int> hidden texts this person asked to see; only reveal() adds one, and it writes who looked */
    #[Locked]
    public array $revealed = [];

    public function openNote(?int $noteId = null): void
    {
        $this->authorize('care', $this->client);

        $note = $noteId !== null ? $this->note($noteId) : null;

        // Opening a hidden text to change it shows it: that goes on the record like any other look.
        if ($note !== null && $note->is_secret && ! in_array($noteId, $this->revealed, true)) {
            app(SaveClientNote::class)->viewed($note, $this->user());
        }

        $this->noteId = $note?->getKey();
        $this->noteTitle = (string) $note?->title;
        $this->noteBody = (string) $note?->body;
        $this->noteSecret = (bool) $note?->is_secret;
        $this->resetErrorBag();
        $this->showNote = true;
    }

    public function saveNote(SaveClientNote $action): void
    {
        $this->authorize('care', $this->client);

        $data = $this->validate([
            'noteTitle' => ['required', 'string', 'max:190'],
            'noteBody' => ['required', 'string', 'max:20000'],
            'noteSecret' => ['boolean'],
        ], attributes: ['noteTitle' => 'title', 'noteBody' => 'text']);

        $action->handle($this->client, $this->noteId !== null ? $this->note($this->noteId) : null, $data['noteTitle'], $data['noteBody'], (bool) $data['noteSecret'], $this->user());

        $this->showNote = false;
        $this->reset('noteId', 'noteTitle', 'noteBody', 'noteSecret');
        $this->tab = 'notes';
        $this->toastSuccess('Saved');
    }

    /** Show a hidden text. Who looked, and when, is written to the log. */
    public function reveal(int $noteId): void
    {
        $this->authorize('care', $this->client);

        $note = $this->note($noteId);

        if (! in_array($noteId, $this->revealed, true)) {
            app(SaveClientNote::class)->viewed($note, $this->user());
            $this->revealed[] = $noteId;
        }
    }

    public function hide(int $noteId): void
    {
        $this->revealed = array_values(array_diff($this->revealed, [$noteId]));
    }

    public function confirmRemoveNote(int $noteId): void
    {
        $this->authorize('care', $this->client);

        $this->askConfirmation('removeNote', [$noteId], 'Remove "'.$this->note($noteId)->title.'"?', 'The text is deleted and cannot be brought back.', 'Remove', 'danger');
    }

    public function removeNote(int $noteId): void
    {
        $this->authorize('care', $this->client);

        app(SaveClientNote::class)->delete($this->note($noteId), $this->user());

        $this->toastSuccess('Removed');
    }

    // ------------------------------------------------------------------ close

    public bool $showClose = false;

    public string $closeNote = '';

    public function openClose(): void
    {
        $this->authorize('close', $this->client);

        $this->closeNote = '';
        $this->showClose = true;
    }

    public function close(CloseClient $action): void
    {
        $this->authorize('close', $this->client);
        $this->validate(['closeNote' => ['nullable', 'string', 'max:500']], attributes: ['closeNote' => 'note']);

        $action->close($this->client, $this->closeNote, $this->user());

        $this->showClose = false;
        $this->client->refresh();
        $this->toastSuccess('Client closed', 'Everything is kept. Reopen them if they come back.');
    }

    public function reopen(CloseClient $action): void
    {
        $this->authorize('reopen', $this->client);

        $action->reopen($this->client, $this->user());

        $this->client->refresh();
        $this->toastSuccess('Client reopened');
    }

    // ----------------------------------------------------------------- render

    protected function paymentRecorded(): void
    {
        $this->client->refresh();
    }

    protected function invoiceCreated(): void
    {
        $this->client->refresh();
    }

    public function render(): View
    {
        $user = $this->user();
        $ready = ClientCare::ready();
        $care = $ready && $user->can('care', $this->client);

        $this->client->loadMissing($ready ? ['department', 'owner', 'helpers', 'handedOverBy', 'closedBy'] : []);

        // The Upsell side sees every sale; front sales only its own.
        $sales = Sale::query()
            ->where('client_id', $this->client->getKey())
            ->when(! $care, fn (Builder $query): Builder => $query->visibleTo($user))
            ->with(['closer', 'department', ...(SalePayments::ready() ? ['payments.recorder'] : [])])
            ->oldest('closed_at')
            ->oldest('id')
            ->get();

        $leads = Lead::query()->visibleTo($user)->where('client_id', $this->client->getKey())->with(['status', 'assignee'])->latest('id')->get();
        LeadSites::attach($leads);

        // Front sales, before the hand-over, sees what it added itself and what sits on its own leads.
        $files = ! $ready ? new Collection : ClientFile::query()
            ->ofClient($this->client)
            ->when(! $care, fn (Builder $query): Builder => $query->where(fn (Builder $own): Builder => $own
                ->where('uploaded_by', $user->getKey())
                ->orWhere(fn (Builder $onLead): Builder => $onLead->where('fileable_type', (new Lead)->getMorphClass())->whereIn('fileable_id', $leads->pluck('id')))))
            ->with('uploader')
            ->latest('id')
            ->get()
            // Every file listed opens: the same rule as the file link itself.
            ->filter(fn (ClientFile $file): bool => $user->can('viewFile', [$this->client, $file]))
            ->values();

        $upsells = $sales->filter(fn (Sale $sale): bool => $sale->isUpsell());
        $team = $ready && $this->client->department !== null ? CareTeams::people($this->client->department) : new Collection;

        return view('sales::livewire.app.client-show', [
            'ready' => $ready,
            'care' => $care,
            'stage' => $this->client->stage(),
            'sales' => $sales,
            'story' => $this->story($sales),
            'totals' => [
                'all' => (float) $sales->sum('amount'),
                'front' => (float) $sales->reject(fn (Sale $sale): bool => $sale->isUpsell())->sum('amount'),
                'upsell' => (float) $upsells->sum('amount'),
                'upsells' => $upsells->count(),
                // Part payments: what this person sees of the deals that is not in yet.
                'due' => (float) $sales->sum(fn (Sale $sale): float => $sale->due()),
            ],
            'paymentsReady' => SalePayments::ready(),
            'brandCurrency' => $this->brand->currency,
            'currencyOptions' => $this->showUpsell ? $this->dealCurrencyOptions($this->brand->currency) : [],
            'leads' => $leads,
            'files' => $files,
            'notes' => $care ? ClientNote::query()->where('client_id', $this->client->getKey())->with('author')->latest('id')->get() : new Collection,
            'categories' => ClientFile::CATEGORIES,
            'maxMb' => ClientFile::MAX_KB / 1024,
            'teams' => $ready ? CareTeams::options($this->brand) : [],
            'helperOptions' => $team->reject(fn (User $person): bool => $person->getKey() === $this->client->owner_id || $this->client->helpers->contains('id', $person->getKey()))->pluck('name', 'id')->all(),
            'sellerOptions' => $this->sellerOptions(),
            'can' => [
                'correct' => $user->isGlobalAdmin(),
                'upsell' => $ready && $user->can('recordUpsell', $this->client),
                // A closed client is reopened first, as everywhere else.
                'handOver' => $ready && ! $this->client->isClosed() && ($user->can('handOver', $this->client) || $user->can('assign', $this->client)),
                'share' => $ready && $this->client->isHandedOver() && $user->can('share', $this->client),
                'close' => $ready && ! $this->client->isClosed() && $user->can('close', $this->client),
                'reopen' => $ready && $this->client->isClosed() && $user->can('reopen', $this->client),
                'addFile' => $ready && $user->can('addFile', $this->client),
                'assign' => $ready && $user->can('assign', $this->client),
            ],
        ])->title($this->client->name);
    }

    // -------------------------------------------------------------- internals

    /**
     * The client's story, oldest first: each sale with the total so far, the
     * hand-over and the day they were closed. Built from the rows themselves,
     * so a corrected sale is corrected here too.
     *
     * @param  Collection<int, Sale>  $sales
     * @return list<array<string, mixed>>
     */
    private function story(Collection $sales): array
    {
        $entries = [];
        $upsell = 0;

        foreach ($sales as $sale) {
            $entries[] = ['type' => 'sale', 'at' => $sale->closed_at, 'order' => 0, 'sale' => $sale, 'number' => $sale->isUpsell() ? ++$upsell : null];
        }

        // Hand-over and closing came with the update; before it a client has only sales.
        $handedOverAt = ClientCare::ready() ? $this->client->handed_over_at : null;
        $closedAt = ClientCare::ready() ? $this->client->closed_at : null;

        if ($handedOverAt !== null) {
            $entries[] = ['type' => 'handover', 'at' => $handedOverAt, 'order' => 1];
        }

        if ($closedAt !== null) {
            $entries[] = ['type' => 'closed', 'at' => $closedAt, 'order' => 2];
        }

        usort($entries, fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['order']] <=> [$b['at']->getTimestamp(), $b['order']]);

        $running = 0.0;

        foreach ($entries as $index => $entry) {
            if ($entry['type'] === 'sale') {
                $running += (float) $entry['sale']->amount;
                $entries[$index]['running'] = $running;
            }
        }

        return $entries;
    }

    /**
     * Whose name an upsell may go under: your own, and for a Manager or an
     * Admin anyone on the client's team.
     *
     * @return array<int, string>
     */
    private function sellerOptions(): array
    {
        $user = $this->user();
        $options = [(int) $user->getKey() => $user->name.' (you)'];

        if (! ClientCare::ready() || ! $user->can('assign', $this->client) || $this->client->department_id === null) {
            return $options;
        }

        $department = $this->client->department()->first();

        foreach ($department !== null ? CareTeams::people($department) : [] as $person) {
            $options[(int) $person->getKey()] ??= $person->name;
        }

        return $options;
    }

    private function sale(int $saleId): Sale
    {
        return Sale::query()->where('client_id', $this->client->getKey())->findOrFail($saleId);
    }

    private function file(int $fileId): ClientFile
    {
        return ClientFile::query()->ofClient($this->client)->findOrFail($fileId);
    }

    private function note(int $noteId): ClientNote
    {
        return ClientNote::query()->where('client_id', $this->client->getKey())->findOrFail($noteId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
