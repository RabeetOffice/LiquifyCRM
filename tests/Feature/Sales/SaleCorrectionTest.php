<?php

declare(strict_types=1);

use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Correcting or removing a recorded sale: Super Admins and Admins only.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Sarah Lee', 'email' => 'sarah@example.com']);
    $this->sale = app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->agent);
    $this->client = Client::query()->sole();
});

it('lets an Admin correct a sale and counts everything again', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])
        ->assertSee('Correct')
        ->call('edit', $this->sale->getKey())
        ->assertSet('amount', '1500')
        ->set('amount', '1250.50')
        ->set('service', 'Publishing and editing')
        ->call('update')
        ->assertHasNoErrors();

    expect((float) $this->sale->refresh()->amount)->toBe(1250.50)
        ->and((float) $this->sale->amount_reporting)->toBe(1250.50)
        ->and($this->sale->service)->toBe('Publishing and editing')
        ->and((float) $this->client->refresh()->lifetime_value)->toBe(1250.50)
        ->and((float) $this->lead->refresh()->value)->toBe(1250.50)
        ->and($this->lead->activities()->where('event', 'sales.updated')->sole()->properties['before']['amount'])->toEqual(1500);
});

it('removes a sale only after the password and takes the lead out of Won', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])
        ->call('confirmRemove', $this->sale->getKey())
        ->set('confirmPassword', 'wrong')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword');

    expect(Sale::query()->count())->toBe(1);

    Livewire::actingAs($this->admin)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])
        ->call('confirmRemove', $this->sale->getKey())
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    $lead = $this->lead->refresh();

    expect(Sale::query()->count())->toBe(0)
        ->and($this->client->refresh()->sales_count)->toBe(0)
        ->and((float) $this->client->lifetime_value)->toBe(0.0)
        ->and($lead->value)->toBeNull()
        ->and($lead->status->is_won)->toBeFalse()
        ->and($lead->status->name)->toBe('Proposal sent')
        ->and($lead->activities()->where('event', 'sales.deleted')->count())->toBe(1);
});

it('keeps a lead Won while it still has another sale', function (): void {
    $second = app(RecordSale::class)->handle($this->lead->refresh(), 400, 'Marketing', now(), $this->agent);

    Livewire::actingAs($this->admin)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])
        ->call('confirmRemove', $second->getKey())
        ->set('confirmPassword', 'password')
        ->call('runConfirmed');

    expect($this->lead->refresh()->status->is_won)->toBeTrue()
        ->and((float) $this->lead->value)->toBe(1500.0)
        ->and((float) $this->client->refresh()->lifetime_value)->toBe(1500.0);
});

it('does not let Managers or Agents change a sale', function (): void {
    $this->actingAs($this->manager)->get('/b/ukph/clients/'.$this->client->getKey())->assertOk()->assertDontSee('Correct')->assertDontSee('Remove');

    Livewire::actingAs($this->manager)->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])->call('edit', $this->sale->getKey())->assertForbidden();
    Livewire::actingAs($this->manager)->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])->call('remove', $this->sale->getKey())->assertForbidden();
    Livewire::actingAs($this->manager)->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])->set('editing', $this->sale->getKey())->set('amount', '1')->set('closedAt', now()->toDateString())->call('update')->assertForbidden();

    expect((float) $this->sale->refresh()->amount)->toBe(1500.0);
});
