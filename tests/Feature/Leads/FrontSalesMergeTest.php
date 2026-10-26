<?php

declare(strict_types=1);

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Front sales merges leads (user request, 26 Oct 2026): the Agents work the
 * leads, so they make two leads of one person one as well. Their own leads
 * and the free ones; a colleague's lead is only named, its Manager merges it.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $this->manager = managerOf($this->front, ['name' => 'Farah Front']);
    $this->agent = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->colleague = agentOf($this->front, ['name' => 'Omar Other']);
    $this->upseller = agentOf($this->upsell, ['name' => 'Usman Upseller']);

    inBrand($this->brand);

    $this->mine = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(),
        'source_type' => LeadSource::Messenger, 'name' => 'Grace Hill', 'email' => 'grace@example.com', 'phone' => null, 'message' => 'Hello from Messenger',
    ]);
});

it('lets an Agent merge a free lead of the same person into their own', function (): void {
    $free = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(),
        'source_type' => LeadSource::Facebook, 'name' => 'Grace Hill', 'email' => 'grace@example.com', 'phone' => '+447700900555',
    ]);

    expect($this->agent->can('merge', $this->mine))->toBeTrue()
        ->and($this->agent->can('merge', $free))->toBeTrue();

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->mine->getKey()])
        ->assertSee('This person has another lead')
        ->assertSee('wire:click="confirmMerge('.$free->getKey().')"', false)
        ->call('confirmMerge', $free->getKey())
        ->call('runConfirmed')
        ->assertDispatched('toast', title: 'Merged into one lead');

    $mine = $this->mine->fresh();

    expect(Lead::query()->whereKey($free->getKey())->exists())->toBeFalse()
        ->and($mine->assigned_to)->toBe($this->agent->getKey())
        ->and($mine->phone)->toBe('+447700900555')
        ->and($mine->activities()->where('event', 'leads.merged')->exists())->toBeTrue();
});

it('names a colleague\'s lead of the same person but leaves the merge to the Manager', function (): void {
    $theirs = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->colleague->getKey(),
        'source_type' => LeadSource::Facebook, 'name' => 'Grace Hill', 'email' => 'grace@example.com',
    ]);

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->mine->getKey()])
        ->assertSee('This person has another lead')
        ->assertSee("It is Omar Other's lead")
        ->assertDontSee('wire:click="confirmMerge('.$theirs->getKey().')"', false)
        ->call('confirmMerge', $theirs->getKey())
        ->assertForbidden();

    // On the colleague's lead the Agent only looks: no hint, no merge.
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $theirs->getKey()])
        ->assertDontSee('This person has another lead');

    expect(Lead::query()->whereKey($theirs->getKey())->exists())->toBeTrue()
        ->and($this->manager->can('merge', $theirs))->toBeTrue()
        ->and($this->upseller->can('merge', $this->mine))->toBeFalse();
});
