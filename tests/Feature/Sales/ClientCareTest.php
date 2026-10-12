<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Core\Enums\AssignmentMode;
use App\Modules\Core\Enums\DepartmentKind;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Departments\Livewire\App\DepartmentForm;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\AssignClient;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Actions\RecordUpsell;
use App\Modules\Sales\Enums\ClientStage;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Livewire\App\ClientsIndex;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\ClientNote;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\SalesReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * After the first sale (12 October): front sales hands the client to an
 * Upsell department, someone there looks after them and records every later
 * sale under their own name, the client has a storage for files and saved
 * text, and front sales no longer sees what happens next.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);

    $this->frontManager = managerOf($this->front, ['name' => 'Farah Front']);
    $this->seller = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->upsellManager = managerOf($this->upsell, ['name' => 'Maya Manager']);
    $this->upseller = agentOf($this->upsell, ['name' => 'Usman Upseller']);
    $this->projectManager = agentOf($this->upsell, ['name' => 'Parveen Project']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->seller->getKey(),
        'name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'phone' => '+447700900123',
    ]);

    // The front sale that makes Sarah a client.
    $this->win = function (float $amount = 1500): Client {
        app(RecordSale::class)->handle($this->lead->fresh(), $amount, 'Publishing package', now(), $this->seller);

        return Client::query()->sole();
    };
});

it('lets a department be an Upsell department', function (): void {
    Livewire::actingAs($this->admin)
        ->test(DepartmentForm::class, ['brand' => $this->brand, 'department' => $this->front])
        ->assertSet('kind', 'sales')
        ->assertSee('Looks after clients after the first sale')
        ->set('kind', 'upsell')
        ->call('save')
        ->assertHasNoErrors()
        ->set('kind', 'something')
        ->call('save')
        ->assertHasErrors('kind');

    expect($this->front->refresh()->kind())->toBe(DepartmentKind::Upsell)
        ->and($this->upsell->refresh()->isUpsell())->toBeTrue();
});

it('hands the client to an upseller from the won lead, with the contract', function (): void {
    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openWon')
        ->assertSee('Hand the client to')
        ->assertSee('Usman Upseller')
        ->set('wonAmount', '1500')
        ->set('wonService', 'Publishing package')
        ->set('wonContract', UploadedFile::fake()->create('contract.pdf', 200, 'application/pdf'))
        ->set('wonHandTo', $this->upsell->getKey().':'.$this->upseller->getKey())
        ->call('recordSale')
        ->assertHasNoErrors()
        ->assertSee('With Upsell: Usman Upseller')
        ->assertSee('contract.pdf')
        ->assertDontSee('Open the client');

    $client = Client::query()->sole();
    $sale = Sale::query()->sole();
    $file = ClientFile::query()->sole();

    expect($client->stage())->toBe(ClientStage::Active)
        ->and($client->department_id)->toBe($this->upsell->getKey())
        ->and($client->owner_id)->toBe($this->upseller->getKey())
        ->and($client->handed_over_by)->toBe($this->seller->getKey())
        ->and($sale->kind())->toBe(SaleKind::Front)
        ->and($file->category)->toBe('contract')
        ->and($file->leadId())->toBe($this->lead->getKey())
        ->and($file->original_name)->toBe('contract.pdf')
        ->and(Storage::disk('local')->exists($file->path))->toBeTrue()
        ->and(Activity::query()->where('event', 'leads.client_handed_over')->where('subject_id', $this->lead->getKey())->exists())->toBeTrue();

    Notification::assertSentTo($this->upseller, AppNotification::class, fn (AppNotification $notification): bool => str_contains($notification->title, 'New client for you: Sarah Lee'));
});

it('lets front sales hand over later, and then shows them nothing more', function (): void {
    $client = ($this->win)();

    expect($client->stage())->toBe(ClientStage::Front);

    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('Not handed to Upsell yet')
        ->call('openHandOver')
        ->set('handTo', (string) $this->upsell->getKey())
        ->call('handOverClient')
        ->assertHasNoErrors()
        ->assertSee('With Upsell, waiting for a person');

    expect($client->refresh()->stage())->toBe(ClientStage::Waiting);
    Notification::assertSentTo($this->upsellManager, AppNotification::class, fn (AppNotification $notification): bool => str_contains($notification->title, 'A client is waiting in Upsell'));

    app(RecordUpsell::class)->handle($client, 800, 'Website', now(), $this->upsellManager);

    // The front seller keeps the lead and the sale they closed; the upsell is not theirs to see.
    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('£1,500')
        ->assertDontSee('£800')
        ->assertDontSee('Website')
        ->call('openHandOver')
        ->assertForbidden();

    $this->actingAs($this->seller)->get('/b/ukph/clients')->assertForbidden();
    $this->actingAs($this->seller)->get('/b/ukph/clients/'.$client->getKey())->assertForbidden();

    // Their Manager saw the client before the hand-over; not after.
    $this->flushSession();
    $this->actingAs($this->frontManager)->get('/b/ukph/clients/'.$client->getKey())->assertForbidden();
    $this->actingAs($this->frontManager)->get('/b/ukph/clients')->assertOk()->assertDontSee('Sarah Lee');

    expect((float) $this->lead->refresh()->value)->toBe(1500.0)
        ->and(SalesReport::forBrand($this->brand, $this->frontManager, now()->format('Y-m'))->topClients()[0]['value'])->toBe(1500.0);
});

it('gives a client to the next person in turn in a round-robin Upsell department', function (): void {
    $this->upsell->update(['assignment_mode' => AssignmentMode::RoundRobin]);
    $client = ($this->win)();

    app(HandOverClient::class)->handle($client, $this->upsell->refresh(), null, $this->seller);

    expect($client->refresh()->owner_id)->toBe($this->upseller->getKey());

    $second = Client::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Adam Young', 'sales_count' => 1]);
    app(HandOverClient::class)->handle($second, $this->upsell->refresh(), null, $this->seller);

    expect($second->refresh()->owner_id)->toBe($this->projectManager->getKey());

    // Only an Upsell department of the brand takes clients, and only its own people.
    expect(fn () => app(HandOverClient::class)->handle($client, $this->front, null, $this->seller))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(HandOverClient::class)->handle($client, $this->upsell, $this->seller, $this->seller))->toThrow(InvalidArgumentException::class);
});

it('records every upsell under the name of whoever sold it and builds the story', function (): void {
    $client = ($this->win)();
    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->seller);

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->assertSee('Record an upsell')
        ->assertSee('Front sale')
        ->assertSee('Sami Seller')
        ->call('openUpsell')
        ->set('upsellAmount', '800')
        ->call('recordUpsell')
        ->assertHasErrors('upsellService')
        ->set('upsellService', 'Website')
        // An Agent cannot put a sale under a colleague's name.
        ->set('upsellSeller', (string) $this->projectManager->getKey())
        ->call('recordUpsell')
        ->assertHasNoErrors()
        ->assertSee('Upsell 1')
        ->assertSee('£2,300 so far');

    $upsell = Sale::query()->where('kind', 'upsell')->sole();

    expect($upsell->user_id)->toBe($this->upseller->getKey())
        ->and($upsell->department_id)->toBe($this->upsell->getKey())
        ->and($upsell->lead_id)->toBeNull()
        ->and($upsell->isUpsell())->toBeTrue();

    // The Manager records one for the project manager; a client can be upsold any number of times.
    Livewire::actingAs($this->upsellManager)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('openUpsell')
        ->set('upsellAmount', '120')
        ->set('upsellService', 'Domain and hosting')
        ->set('upsellSeller', (string) $this->projectManager->getKey())
        ->call('recordUpsell')
        ->assertHasNoErrors()
        ->assertSee('Upsell 2')
        ->assertSeeInOrder(['Publishing package', 'Website', 'Domain and hosting']);

    $client->refresh();
    $month = now()->format('Y-m');

    expect((float) $client->lifetime_value)->toBe(2420.0)
        ->and($client->sales_count)->toBe(3)
        ->and(Sale::query()->where('service', 'Domain and hosting')->value('user_id'))->toBe($this->projectManager->getKey())
        ->and(SalesReport::forPerson($this->brand, $this->upseller, $month)->totals()['revenue'])->toBe(800.0)
        ->and(SalesReport::forPerson($this->brand, $this->seller, $month)->totals()['revenue'])->toBe(1500.0)
        ->and(SalesReport::forAdmin($month, (int) $this->brand->getKey())->totals())->toMatchArray(['revenue' => 2420.0, 'count' => 3, 'upsells' => 2, 'upsell_revenue' => 920.0]);

    // The project manager is not on this client, so cannot open it or sell on it.
    $this->actingAs($this->projectManager)->get('/b/ukph/clients/'.$client->getKey())->assertForbidden();
});

it('lets the people added to a client work on it under their own name', function (): void {
    $client = ($this->win)();
    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->seller);

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->set('helperId', (string) $this->seller->getKey())
        ->call('addHelper')
        ->assertHasErrors('helperId')
        ->set('helperId', (string) $this->projectManager->getKey())
        ->call('addHelper')
        ->assertHasNoErrors()
        ->assertSee('Also on this client')
        ->assertSee('Parveen Project');

    $this->actingAs($this->projectManager)->get('/b/ukph/clients')->assertOk()->assertSee('My clients')->assertSee('Sarah Lee');

    Livewire::actingAs($this->projectManager)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('openUpsell')
        ->set('upsellAmount', '300')
        ->set('upsellService', 'Book cover')
        ->call('recordUpsell')
        ->assertHasNoErrors()
        // Giving the client to someone else is for the Manager.
        ->call('openHandOver')
        ->assertForbidden();

    expect(Sale::query()->where('service', 'Book cover')->value('user_id'))->toBe($this->projectManager->getKey());

    Livewire::actingAs($this->upsellManager)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('removeHelper', $this->projectManager->getKey())
        ->call('openHandOver')
        ->set('handTo', $this->upsell->getKey().':'.$this->projectManager->getKey())
        ->call('handOver')
        ->assertHasNoErrors();

    expect($client->refresh()->owner_id)->toBe($this->projectManager->getKey());
    $this->actingAs($this->upseller)->get('/b/ukph/clients/'.$client->getKey())->assertForbidden();
});

it('keeps the files of a client in private storage, open only to the people on the client', function (): void {
    $client = ($this->win)();

    // Front sales adds the contract from the lead.
    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openClientFile')
        ->set('clientUploads', [UploadedFile::fake()->create('signed-contract.pdf', 300, 'application/pdf')])
        ->call('saveClientFiles')
        ->assertHasNoErrors()
        ->assertSee('signed-contract.pdf');

    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->seller);

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('openUpload')
        ->set('uploadCategory', 'invoice')
        ->set('uploads', [UploadedFile::fake()->create('shell.php', 1, 'text/plain')])
        ->call('saveFiles')
        ->assertHasErrors('uploads.0')
        // Choosing again adds to the list, so the refused file is taken out first.
        ->set('uploads', [])
        ->set('uploads', [UploadedFile::fake()->create('invoice-001.pdf', 120, 'application/pdf'), UploadedFile::fake()->image('cover.png')])
        ->call('saveFiles')
        ->assertHasNoErrors()
        ->assertSet('tab', 'files')
        ->assertSee('signed-contract.pdf')
        ->assertSee('invoice-001.pdf')
        ->assertSee('cover.png');

    $contract = ClientFile::query()->where('original_name', 'signed-contract.pdf')->sole();
    $invoice = ClientFile::query()->where('original_name', 'invoice-001.pdf')->sole();
    $url = fn (ClientFile $file): string => '/b/ukph/clients/'.$client->getKey().'/files/'.$file->getKey();

    expect(ClientFile::query()->count())->toBe(3)
        ->and($invoice->category)->toBe('invoice')
        // Added on the client's page it belongs to the client; added from the lead, to the lead.
        ->and($invoice->fileable_type)->toBe($client->getMorphClass())
        ->and($contract->leadId())->toBe($this->lead->getKey())
        ->and($invoice->path)->toStartWith('clients/'.$this->brand->getKey().'/'.$client->getKey().'/')
        ->and($invoice->path)->not->toContain('invoice-001');

    $this->actingAs($this->upseller)->get($url($invoice))->assertOk()->assertHeader('content-disposition', 'attachment; filename=invoice-001.pdf');
    $this->actingAs($this->upseller)->get($url($invoice).'?open=1')->assertOk()->assertHeader('content-type', 'application/pdf');

    // The seller opens the contract they added, not what Upsell filed later; a stranger opens nothing.
    $this->actingAs($this->seller)->get($url($contract))->assertOk();
    $this->actingAs($this->seller)->get($url($invoice))->assertForbidden();
    $this->actingAs($this->projectManager)->get($url($contract))->assertForbidden();

    Livewire::actingAs($this->upsellManager)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('removeFile', $invoice->getKey());

    expect(ClientFile::query()->whereKey($invoice->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($invoice->path))->toBeFalse();
});

it('stores text a client shared encrypted and keeps a hidden one covered until someone asks', function (): void {
    $client = ($this->win)();
    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->seller);

    $screen = Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('openNote')
        ->set('noteTitle', 'Hosting login')
        ->set('noteBody', "user: sarah\npass: Tr1cky-Pass")
        ->set('noteSecret', true)
        ->call('saveNote')
        ->assertHasNoErrors()
        ->assertSee('Hosting login')
        ->assertDontSee('Tr1cky-Pass');

    $note = ClientNote::query()->sole();

    expect($note->body)->toContain('Tr1cky-Pass')
        ->and((string) DB::table('client_notes')->value('body'))->not->toContain('Tr1cky-Pass')
        ->and(Activity::query()->where('event', 'clients.note_added')->sole()->properties->toArray())->not->toHaveKey('body');

    $screen->call('reveal', $note->getKey())->assertSee('Tr1cky-Pass')->call('hide', $note->getKey())->assertDontSee('Tr1cky-Pass');

    expect(Activity::query()->where('event', 'clients.secret_viewed')->where('causer_id', $this->upseller->getKey())->count())->toBe(1);

    // Front sales never reaches the saved text, not even before the hand-over of another client.
    Livewire::actingAs($this->admin)->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])->assertSee('Saved text');

    $other = Client::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Adam Young', 'sales_count' => 1]);
    Sale::query()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'client_id' => $other->getKey(), 'user_id' => $this->seller->getKey(), 'amount' => 400, 'currency' => 'GBP', 'closed_at' => now()]);

    Livewire::actingAs($this->frontManager)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $other])
        ->assertDontSee('Saved text')
        ->call('openNote')
        ->assertForbidden();
});

it('closes a client when the work is over and reopens them', function (): void {
    $client = ($this->win)();
    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->seller);

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('openClose')
        ->set('closeNote', 'Book published')
        ->call('close')
        ->assertSee('Closed client')
        ->assertSee('Book published')
        ->assertDontSee('Record an upsell')
        ->call('openUpsell')
        ->assertForbidden();

    expect($client->refresh()->stage())->toBe(ClientStage::Closed)
        ->and(fn () => app(RecordUpsell::class)->handle($client, 100, 'Extra', now(), $this->upseller))->toThrow(InvalidArgumentException::class);

    // Reopening is for the Manager.
    Livewire::actingAs($this->upseller)->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])->call('reopen')->assertForbidden();
    Livewire::actingAs($this->upsellManager)->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])->call('reopen')->assertSee('Record an upsell');

    expect($client->refresh()->stage())->toBe(ClientStage::Active);
});

it('shows clients by where they stand and lets a Manager give several to a person', function (): void {
    $sarah = ($this->win)();
    $adam = Client::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Adam Young', 'sales_count' => 1, 'lifetime_value' => 400]);
    $nora = Client::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Nora Quinn', 'sales_count' => 1, 'lifetime_value' => 250]);

    app(HandOverClient::class)->handle($adam, $this->upsell, null, $this->admin);
    app(HandOverClient::class)->handle($nora, $this->upsell, null, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertViewHas('counts', ['waiting' => 2, 'front' => 1])
        ->assertSee('Waiting in Upsell')
        ->assertSee('Not handed over')
        ->set('tab', 'front')
        ->assertSee('Sarah Lee')
        ->assertDontSee('Adam Young')
        ->set('tab', 'waiting')
        ->assertDontSee('Sarah Lee')
        ->assertSeeInOrder(['Adam Young', 'Nora Quinn']);

    // The Upsell Manager sees the clients of their department and hands them out.
    Livewire::actingAs($this->upsellManager)
        ->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertDontSee('Sarah Lee')
        ->set('selected', [$adam->getKey(), $nora->getKey(), $sarah->getKey()])
        ->call('openGive')
        ->set('giveTo', $this->upsell->getKey().':'.$this->upseller->getKey())
        ->call('give')
        ->assertHasNoErrors()
        ->assertSet('selected', [])
        ->set('tab', 'waiting')
        ->assertSee('Nobody is waiting');

    expect($adam->refresh()->owner_id)->toBe($this->upseller->getKey())
        ->and($nora->refresh()->owner_id)->toBe($this->upseller->getKey())
        ->and($sarah->refresh()->stage())->toBe(ClientStage::Front);

    // The upseller sees only their own, and has nobody to give them to.
    Livewire::actingAs($this->upseller)
        ->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertSee('My clients')
        ->assertSee('Adam Young')
        ->assertDontSee('Sarah Lee')
        ->call('openGive')
        ->assertForbidden();

    app(AssignClient::class)->handle($adam, null, $this->upsellManager);

    expect($adam->refresh()->stage())->toBe(ClientStage::Waiting);
    $this->actingAs($this->upseller)->get('/b/ukph/clients/'.$adam->getKey())->assertForbidden();
});

it('makes the Clients screen the home of someone who works only on an Upsell team', function (): void {
    // No dashboard of leads for them: they land on their clients, with tips about clients.
    $this->actingAs($this->upseller)->get('/b/ukph')->assertRedirect('/b/ukph/clients');
    $this->actingAs($this->upseller)->get('/b/ukph/clients')->assertOk()
        ->assertSee('My clients')
        ->assertSee('Your clients are under My clients')
        ->assertDontSee('Pipeline')
        ->assertDontSee('Follow-ups');

    $this->flushSession();
    $this->actingAs($this->upsellManager)->get('/b/ukph')->assertRedirect('/b/ukph/clients');
    $this->actingAs($this->upsellManager)->get('/b/ukph/leads/distribute')->assertOk()
        ->assertSee('How clients are shared')
        ->assertDontSee('Which leads');

    // Front sales keeps its dashboard and its leads, and has no clients menu.
    $this->flushSession();
    $this->actingAs($this->seller)->get('/b/ukph')->assertOk()->assertSee('My leads')->assertDontSee('My clients');

    // Given a lead all the same, the upseller gets the lead screens back.
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'assigned_to' => $this->upseller->getKey()]);

    $this->flushSession();
    $this->actingAs($this->upseller)->get('/b/ukph')->assertOk()->assertSee('My leads')->assertSee('My clients');
});
