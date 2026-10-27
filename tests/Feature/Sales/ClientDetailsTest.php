<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\AssignClient;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Models\Client;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * A client's name, phone and email (user request, 27 Oct 2026): "Can't edit
 * client info. Need edit access. Do this for agent as well." Everybody who
 * works the client corrects them, Agents included; nobody else does.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $this->other = department($this->brand, ['name' => 'Project Management', 'kind' => 'upsell']);

    $this->frontManager = managerOf($this->front, ['name' => 'Farah Front']);
    $this->seller = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->upsellManager = managerOf($this->upsell, ['name' => 'Maya Manager']);
    $this->upseller = agentOf($this->upsell, ['name' => 'Usman Upseller']);
    $this->helper = agentOf($this->upsell, ['name' => 'Parveen Project']);
    $this->stranger = agentOf($this->other, ['name' => 'Omar Other']);

    inBrand($this->brand);

    $lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->seller->getKey(),
        'name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'phone' => '+447700900123',
    ]);

    app(RecordSale::class)->handle($lead, 1500, 'Publishing package', now(), $this->seller);
    $this->lead = $lead->refresh();
    $this->client = Client::query()->sole();
});

it('lets the Agent who looks after a client correct their name, phone and email', function (): void {
    app(HandOverClient::class)->handle($this->client, $this->upsell, $this->upseller, $this->seller);

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])
        ->assertSee('Edit details')
        ->call('openDetails')
        ->assertSet('details', ['name' => 'Sarah Lee', 'phone' => '+447700900123', 'email' => 'sarah@example.com'])
        ->set('details.name', '')
        ->set('details.email', 'not an email')
        ->call('saveDetails')
        ->assertHasErrors(['details.name', 'details.email'])
        ->set('details.name', 'Sarah Lee-Khan')
        ->set('details.phone', '+447700900999')
        ->set('details.email', 'Sarah.Khan@Example.com')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSet('showDetails', false)
        ->assertSee('Sarah Lee-Khan')
        ->assertSee('+447700900999');

    $client = $this->client->refresh();
    $line = Activity::query()->where('event', 'clients.details_updated')->sole();

    expect($client->name)->toBe('Sarah Lee-Khan')
        ->and($client->phone)->toBe('+447700900999')
        ->and($client->email)->toBe('sarah.khan@example.com')
        // The lead keeps what was sent, and the log says what changed, not to what.
        ->and($this->lead->refresh()->name)->toBe('Sarah Lee')
        ->and($line->causer_id)->toBe($this->upseller->getKey())
        ->and($line->description)->toBe('Details updated: name, phone, email')
        ->and((string) json_encode($line->properties))->not->toContain('sarah.khan');
});

it('lets a helper, the Manager of the team and an Admin edit too, and an empty phone clears it', function (): void {
    app(HandOverClient::class)->handle($this->client, $this->upsell, $this->upseller, $this->seller);
    app(AssignClient::class)->addHelper($this->client->refresh(), $this->helper, $this->upsellManager);

    foreach ([$this->helper, $this->upsellManager, admin()] as $person) {
        Livewire::actingAs($person)
            ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client->refresh()])
            ->call('openDetails')
            ->assertSet('showDetails', true);
    }

    Livewire::actingAs($this->helper)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client->refresh()])
        ->call('openDetails')
        ->set('details.phone', '')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($this->client->refresh()->phone)->toBeNull()
        ->and($this->client->email)->toBe('sarah@example.com');
});

it('lets the front Manager edit before the hand-over and nobody outside the client at all', function (): void {
    Livewire::actingAs($this->frontManager)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $this->client])
        ->call('openDetails')
        ->assertSet('showDetails', true);

    app(HandOverClient::class)->handle($this->client, $this->upsell, $this->upseller, $this->seller);
    $client = $this->client->refresh();

    // Somebody on another Upsell team, and front sales after the hand-over: not their client.
    expect($this->stranger->can('update', $client))->toBeFalse()
        ->and($this->frontManager->can('update', $client))->toBeFalse()
        ->and($this->seller->can('update', $client))->toBeFalse()
        ->and($this->upseller->can('update', $client))->toBeTrue();

    $this->actingAs($this->stranger)->get('/b/ukph/clients/'.$client->getKey())->assertForbidden();
});
