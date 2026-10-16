<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Navigation\Menu;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Actions\MergeLeads;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsBin;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadNote;
use App\Modules\Leads\Models\Task;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Actions\StoreClientFile;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The bin: leads an Admin deleted wait there, with who deleted them, until
 * an Admin restores them or deletes them forever (the bin does that by
 * itself after 30 days). Merged leads are not in it.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin(['name' => 'Ada Admin']);

    inBrand($this->brand);
});

/** Deletes leads the way the Leads screen does, so the bin sees a "deleted" line. */
function binLeads(mixed $test, Lead ...$leads): void
{
    Livewire::actingAs($test->admin)
        ->test(LeadsIndex::class, ['brand' => $test->brand])
        ->set('selected', array_map(fn (Lead $lead): int => (int) $lead->getKey(), $leads))
        ->call('bulkDelete');
}

it('shows the deleted leads to an Admin, with who deleted them', function (): void {
    $gone = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Deleted Dana']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Living Lou']);

    binLeads($this, $gone);

    $this->actingAs($this->admin)
        ->get('/b/ukph/leads/bin')
        ->assertOk()
        ->assertSee('Bin')
        ->assertSee('Deleted Dana')
        ->assertSee('by Ada Admin')
        ->assertDontSee('Living Lou');
});

it('keeps Managers and Agents out of the bin', function (): void {
    $this->actingAs($this->manager)->get('/b/ukph/leads/bin')->assertForbidden();

    $this->flushSession();

    $this->actingAs($this->agent)->get('/b/ukph/leads/bin')->assertForbidden();
});

it('puts the Bin in the menu and on Leads for Admins only', function (): void {
    $labels = fn ($user): array => collect(Menu::for($user, $this->brand, 'brand.leads.index'))->flatMap(fn (array $group): array => array_column($group['items'], 'label'))->all();

    expect($labels($this->admin))->toContain('Bin')
        ->and($labels($this->manager))->not->toContain('Bin');

    Livewire::actingAs($this->admin)->test(LeadsIndex::class, ['brand' => $this->brand])->assertSee('/b/ukph/leads/bin');
    Livewire::actingAs($this->manager)->test(LeadsIndex::class, ['brand' => $this->brand])->assertDontSee('/b/ukph/leads/bin');
});

it('restores a lead with its owner, and logs it', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Back Again']);

    binLeads($this, $lead);
    expect(Lead::query()->find($lead->getKey()))->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->assertSee('Back Again')
        ->call('restore', $lead->getKey())
        ->assertDontSee('Back Again');

    $restored = Lead::query()->findOrFail($lead->getKey());

    expect($restored->assigned_to)->toBe($this->agent->getKey())
        ->and($restored->department_id)->toBe($this->department->getKey());

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.restored', 'subject_id' => $lead->getKey(), 'causer_id' => $this->admin->getKey()]);
});

it('sends a restored lead to the queue when its owner has left the brand', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);

    binLeads($this, $lead);

    /** @var Membership $membership */
    $membership = $this->agent->membershipFor($this->brand);
    app(RemoveMemberFromBrand::class)->handle($membership, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->call('restore', $lead->getKey());

    $restored = Lead::query()->findOrFail($lead->getKey());

    expect($restored->assigned_to)->toBeNull()
        ->and($restored->department_id)->toBe($this->department->getKey());
});

it('restores every ticked lead at once', function (): void {
    $leads = Lead::factory()->count(3)->create(['brand_id' => $this->brand->getKey()]);

    binLeads($this, ...$leads->all());
    expect(Lead::query()->count())->toBe(0);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->set('selected', $leads->take(2)->pluck('id')->all())
        ->call('restoreSelected')
        ->assertSet('selected', []);

    expect(Lead::query()->count())->toBe(2)
        ->and(Lead::query()->inBin()->count())->toBe(1);
});

it('leaves out a lead that was merged into another, and will not restore it', function (): void {
    $keep = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Kept Kim']);
    $other = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Merged Max']);

    app(MergeLeads::class)->handle($keep, $other, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->assertSee('The bin is empty')
        ->assertDontSee('Merged Max');

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->call('restore', $other->getKey())
        ->assertNotFound();

    expect(Lead::query()->find($other->getKey()))->toBeNull();
});

it('never shows or restores a lead of another brand', function (): void {
    $otherBrand = brand(['slug' => 'other']);
    $foreign = Lead::factory()->create(['brand_id' => $otherBrand->getKey(), 'name' => 'Foreign Fay']);

    inBrand($otherBrand);
    binLeads((object) ['admin' => $this->admin, 'brand' => $otherBrand], $foreign);
    inBrand($this->brand);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->assertDontSee('Foreign Fay')
        ->call('restore', $foreign->getKey())
        ->assertNotFound();

    expect(Lead::query()->forBrand($otherBrand)->inBin()->count())->toBe(1);
});

it('points an Admin from a deleted lead to the bin', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Gone Gus']);

    // Open on one screen, deleted on another.
    $panel = Livewire::actingAs($this->admin)->test(LeadPanel::class, ['leadId' => $lead->getKey()]);

    binLeads($this, $lead);

    $panel->call('$refresh')
        ->assertSee('This lead is in the bin')
        ->assertSee('Open the Bin');
});

/** Deletes one lead forever from the bin, the way the screen does: the dialog, then the password. */
function deleteForever(mixed $test, Lead $lead): void
{
    Livewire::actingAs($test->admin)
        ->test(LeadsBin::class, ['brand' => $test->brand])
        ->call('confirmDeleteForever', $lead->getKey())
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertHasNoErrors();
}

it('deletes a lead forever with its notes, follow-ups, chat, events, bell entries and history', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Private Pat', 'email' => 'pat@example.com']);

    app(AddLeadNote::class)->handle($lead, 'Called, wants a quote', $this->admin);
    app(ScheduleFollowUp::class)->handle($lead, now()->addDay(), 'Send the quote', $this->admin);
    $conversation = Conversation::query()->create(['brand_id' => $this->brand->getKey(), 'lead_id' => $lead->getKey(), 'contact_external_id' => '111222333', 'contact_name' => 'Private Pat']);
    Message::query()->create(['conversation_id' => $conversation->getKey(), 'direction' => 'in', 'body' => 'Hello, how much is it?']);
    InboundEvent::query()->create(['source' => 'site', 'brand_id' => $this->brand->getKey(), 'payload' => ['email' => 'pat@example.com'], 'status' => 'done', 'lead_id' => $lead->getKey()]);

    $bell = fn (string $url): DatabaseNotification => DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'app',
        'notifiable_type' => $this->admin->getMorphClass(),
        'notifiable_id' => $this->admin->getKey(),
        'data' => ['title' => 'New lead', 'url' => $url],
    ]);
    $bell($this->brand->url('leads/'.$lead->getKey()));
    $bell($this->brand->url('leads/'.$lead->getKey().'9'));

    binLeads($this, $lead);
    deleteForever($this, $lead);

    expect(Lead::query()->withTrashed()->find($lead->getKey()))->toBeNull()
        ->and(LeadNote::query()->where('lead_id', $lead->getKey())->exists())->toBeFalse()
        ->and(Task::query()->where('lead_id', $lead->getKey())->exists())->toBeFalse()
        ->and(Conversation::query()->acrossBrands()->whereKey($conversation->getKey())->exists())->toBeFalse()
        ->and(Message::query()->where('conversation_id', $conversation->getKey())->exists())->toBeFalse()
        ->and(InboundEvent::query()->where('lead_id', $lead->getKey())->exists())->toBeFalse()
        ->and(DatabaseNotification::query()->count())->toBe(1)
        ->and(Activity::query()->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->getKey())->exists())->toBeFalse();

    // One line says it happened, by whom, and nothing about the person.
    $line = Activity::query()->where('event', 'leads.deleted_forever')->sole();

    expect($line->causer_id)->toBe($this->admin->getKey())
        ->and($line->brand_id)->toBe($this->brand->getKey())
        ->and($line->properties?->get('lead_id'))->toBe($lead->getKey())
        ->and((string) json_encode($line->toArray()))->not->toContain('Pat')
        ->and((string) json_encode($line->toArray()))->not->toContain('pat@example.com');
});

it('keeps the sale and the client of a won lead, and moves its files to the client', function (): void {
    Storage::fake('local');

    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $sale = app(RecordSale::class)->handle($lead, 1500, 'Publishing package', now(), $this->agent);
    $client = Client::query()->findOrFail($sale->client_id);
    $file = app(StoreClientFile::class)->handle($client, UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'), 'contract', $this->admin, $lead->fresh());

    binLeads($this, $lead);
    deleteForever($this, $lead);

    $file->refresh();
    $kept = Sale::query()->findOrFail($sale->getKey());

    expect(Lead::query()->withTrashed()->find($lead->getKey()))->toBeNull()
        ->and($kept->lead_id)->toBeNull()
        ->and((float) $kept->amount)->toBe(1500.0)
        ->and(Client::query()->whereKey($client->getKey())->exists())->toBeTrue()
        ->and($file->fileable_type)->toBe($client->getMorphClass())
        ->and($file->fileable_id)->toBe($client->getKey())
        ->and(ClientFile::ofClient($client)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'leads.deleted_forever')->sole()->properties?->get('had_sale'))->toBeTrue();

    Storage::disk('local')->assertExists($file->path);
});

it('asks for the password and refuses a direct call', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);

    binLeads($this, $lead);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->call('confirmDeleteForever', $lead->getKey())
        ->assertSet('confirm.password', true)
        ->set('confirmPassword', 'not my password')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword');

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->call('deleteForever', $lead->getKey())
        ->assertForbidden();

    expect(Lead::query()->inBin()->whereKey($lead->getKey())->exists())->toBeTrue();
});

it('deletes the ticked leads forever, and a lead merged into one of them', function (): void {
    $keep = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Kept Kim']);
    $twin = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Twin Kim']);
    $other = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);
    $stays = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);

    app(MergeLeads::class)->handle($keep, $twin, $this->admin);
    binLeads($this, $keep, $other, $stays);

    // What was ticked when the dialog opened is deleted, even if the ticks change meanwhile.
    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->set('selected', [$keep->getKey(), $other->getKey()])
        ->call('confirmDeleteSelected')
        ->set('selected', [$stays->getKey()])
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect(Lead::query()->withTrashed()->whereKey([$keep->getKey(), $twin->getKey(), $other->getKey()])->exists())->toBeFalse()
        ->and(Lead::query()->inBin()->whereKey($stays->getKey())->exists())->toBeTrue();
});

it('empties the bin by itself after 30 days', function (): void {
    $old = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);
    $recent = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);

    binLeads($this, $old, $recent);
    Lead::query()->withTrashed()->whereKey($old->getKey())->update(['deleted_at' => now()->subDays(31)]);
    Lead::query()->withTrashed()->whereKey($recent->getKey())->update(['deleted_at' => now()->subDays(29)]);

    $this->artisan('leads:empty-bin')->assertSuccessful();

    expect(Lead::query()->withTrashed()->find($old->getKey()))->toBeNull()
        ->and(Lead::query()->inBin()->whereKey($recent->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'leads.deleted_forever')->sole()->properties?->get('automatic'))->toBeTrue();
});

it('lets Admins delete forever, never a Manager', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey()]);

    binLeads($this, $lead);
    $binned = Lead::query()->withTrashed()->findOrFail($lead->getKey());

    expect($this->manager->can('forceDelete', $binned))->toBeFalse()
        ->and($this->admin->can('forceDelete', $binned))->toBeTrue();
});
