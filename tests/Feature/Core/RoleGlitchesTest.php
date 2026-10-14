<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Models\Client;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Glitches found on 14 October by opening every screen as each role: the
 * profile showed Managers and Agents the admin area's menu and called them
 * "Member", and a client's page linked an Upsell agent to front sales' lead,
 * which they may not open.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->other = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Grace Manager']);
    $this->agent = agentOf($this->front, ['name' => 'Ava Agent']);
});

it('shows a Manager or Agent on their profile the menu of their brand, never the admin area', function (): void {
    foreach ([$this->manager, $this->agent] as $person) {
        $this->actingAs($person)->get('/profile')
            ->assertOk()
            ->assertSee('UK Publishing House')
            ->assertSee(route('brand.follow-ups', ['brand' => 'ukph']), false)
            ->assertDontSee(route('admin.people.index'), false)
            ->assertDontSee(route('admin.integrations'), false)
            ->assertDontSee(route('admin.system'), false);
    }

    $this->actingAs(admin())->get('/profile')->assertOk()->assertSee(route('admin.people.index'), false);
});

it('remembers the brand a person was last in for their profile menu', function (): void {
    app(AddMemberToBrand::class)->handle($this->other, $this->agent, BrandRole::Agent);

    $this->actingAs($this->agent)->get('/b/anz')->assertOk();

    $this->actingAs($this->agent)->get('/profile')->assertOk()->assertSee(route('brand.follow-ups', ['brand' => 'anz']), false);
});

it('gives somebody without a brand no menu at all', function (): void {
    $loner = User::factory()->create()->refresh();

    $this->actingAs($loner)->get('/profile')->assertOk()->assertDontSee(route('admin.people.index'), false);
});

it('names the role outside a brand by what the person is', function (): void {
    expect($this->manager->roleLabelIn(null))->toBe('Manager')
        ->and($this->agent->roleLabelIn(null))->toBe('Agent');

    app(AddMemberToBrand::class)->handle($this->other, $this->manager, BrandRole::Agent);

    expect($this->manager->fresh()->roleLabelIn(null))->toBe('Manager and Agent')
        ->and(User::factory()->create()->roleLabelIn(null))->toBe('Member');

    $this->actingAs($this->agent)->get('/profile')->assertSee('Agent')->assertDontSee('<dd>Member</dd>', false);
});

it('does not link an Upsell agent to the lead of a sale they may not open', function (): void {
    $upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $upseller = agentOf($upsell, ['name' => 'Usman Upseller']);
    inBrand($this->brand);

    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Sarah Lee']);
    app(RecordSale::class)->handle($lead, 1500, 'Publishing package', now(), $this->agent);
    $client = Client::query()->sole();
    app(HandOverClient::class)->handle($client, $upsell, $upseller, $this->agent);

    $leadUrl = route('brand.leads.show', ['brand' => 'ukph', 'lead' => $lead->getKey()]);

    Livewire::actingAs($upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client->fresh()])
        ->assertOk()
        ->assertDontSee($leadUrl, false);

    Livewire::actingAs(admin())
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client->fresh()])
        ->assertSee($leadUrl, false);
});
