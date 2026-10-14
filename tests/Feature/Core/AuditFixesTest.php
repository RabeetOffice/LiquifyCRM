<?php

declare(strict_types=1);

use App\Modules\Auth\Livewire\ProfileSecurity;
use App\Modules\Departments\Livewire\App\DepartmentForm;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Livewire\App\ClientsIndex;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientNote;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The role audit of 14 October: what the screens hide must also be refused
 * on the server, and front sales must not see what another team sold.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Grace Manager']);
    $this->agent = agentOf($this->front, ['name' => 'Ava Agent']);
    $this->upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $this->upseller = agentOf($this->upsell, ['name' => 'Usman Upseller']);
    inBrand($this->brand);
});

it('runs a password-confirmed step only through its dialog', function (): void {
    Livewire::actingAs($this->agent)->test(ProfileSecurity::class)->call('doDisableTwoFactor')->assertForbidden();
    Livewire::actingAs($this->agent)->test(ProfileSecurity::class)->call('doRegenerateRecoveryCodes')->assertForbidden();

    // What the dialog runs, and whether it asks the password, is not the browser's to change.
    expect(fn () => Livewire::actingAs($this->agent)->test(ProfileSecurity::class)->set('confirm', ['method' => 'doDisableTwoFactor', 'params' => [], 'title' => 'x', 'body' => null, 'label' => 'x', 'tone' => 'danger', 'password' => false]))
        ->toThrow(Exception::class);
    expect(fn () => Livewire::actingAs($this->agent)->test(ProfileSecurity::class)->set('showRecoveryCodes', true))->toThrow(Exception::class);
});

it('does not let Undo mark a lead Won or give it to somebody the assign menu would not offer', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $won = LeadStatus::query()->forBrand($this->brand)->where('is_won', true)->value('id');
    $before = $lead->status_id;

    Livewire::actingAs($this->agent)->test(LeadsIndex::class, ['brand' => $this->brand])->call('undoLeadChange', $lead->getKey(), 'status', $won);
    Livewire::actingAs($this->manager)->test(LeadsIndex::class, ['brand' => $this->brand])->call('undoLeadChange', $lead->getKey(), 'assign', $this->upseller->getKey());

    expect($lead->fresh()->status_id)->toBe($before)
        ->and($lead->fresh()->assigned_to)->toBe($this->agent->getKey());
});

it('keeps a Manager seat for Managers of the brand, and a department to its own people', function (): void {
    Livewire::actingAs(admin())
        ->test(DepartmentForm::class, ['brand' => $this->brand, 'department' => $this->front])
        ->call('changeRole', $this->agent->getKey(), 'manager')
        ->call('changeRole', $this->upseller->getKey(), 'agent')
        ->assertNotFound();

    expect($this->agent->fresh()->managesDepartment($this->front))->toBeFalse()
        ->and($this->upseller->fresh()->belongsToDepartment($this->front))->toBeFalse();
});

it('shows front sales only what its own team sold of a client', function (): void {
    $other = department($this->brand, ['name' => 'Second Front Sales']);
    $otherSeller = agentOf($other, ['name' => 'Omar Other']);

    $mine = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Sarah Lee', 'email' => 'sarah@example.com']);
    $theirs = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $other->getKey(), 'assigned_to' => $otherSeller->getKey(), 'name' => 'Sarah Lee', 'email' => 'sarah@example.com']);
    app(RecordSale::class)->handle($mine, 1500, 'Publishing package', now(), $this->agent);
    app(RecordSale::class)->handle($theirs, 900, 'Cover design', now(), $otherSeller);

    expect((float) Client::query()->sole()->lifetime_value)->toBe(2400.0);

    Livewire::actingAs($this->manager)
        ->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertSee('£1,500')
        ->assertDontSee('£2,400');

    Livewire::actingAs(admin())->test(ClientsIndex::class, ['brand' => $this->brand])->assertSee('£2,400');
});

it('shows a hidden client text only through Show, which writes who looked', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Sarah Lee']);
    app(RecordSale::class)->handle($lead, 1500, 'Publishing package', now(), $this->agent);
    $client = Client::query()->sole();
    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->agent);

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client->fresh()])
        ->call('openNote')
        ->set('noteTitle', 'Hosting login')
        ->set('noteBody', 'pass: Tr1cky-Pass')
        ->set('noteSecret', true)
        ->call('saveNote');

    $note = ClientNote::query()->sole();

    expect(fn () => Livewire::actingAs($this->upseller)->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client->fresh()])->set('revealed', [$note->getKey()]))
        ->toThrow(Exception::class);
});
