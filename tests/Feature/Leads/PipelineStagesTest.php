<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\ChangePipelineStages;
use App\Modules\Leads\Exceptions\StageInUse;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Leads\Livewire\App\PipelineStages;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Pipeline stages (user request, 12 Nov 2026): Admins and a brand's Managers
 * add a stage between two others for that brand only ("Connected" between
 * Contacted and Qualified for Melbourne), rename it, colour it, move it, and
 * remove it only while no lead is in it. New, Won and Lost always stay.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'Melbourne Print Publish', 'slug' => 'melbourne']);
    $this->other = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Mona Manager']);
    $this->agent = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->admin = admin();

    inBrand($this->brand);
});

/** @return list<string> */
function stageNames(int $brandId): array
{
    return LeadStatus::query()->forBrand($brandId)->ordered()->pluck('name')->all();
}

function stageOf(int $brandId, string $name): LeadStatus
{
    return LeadStatus::query()->forBrand($brandId)->where('name', $name)->sole();
}

it('lets a Manager add a stage between Contacted and Qualified for their brand only', function (): void {
    $contacted = stageOf($this->brand->getKey(), 'Contacted');

    Livewire::actingAs($this->manager)
        ->test(PipelineStages::class, ['brand' => $this->brand])
        ->call('create', $contacted->getKey())
        ->assertSet('after', $contacted->getKey())
        ->set('name', '  Connected ')
        ->set('color', 'teal')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSee('Connected');

    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Contacted', 'Connected', 'Qualified', 'Proposal sent', 'Won', 'Lost'])
        ->and(stageNames($this->other->getKey()))->toBe(['New', 'Contacted', 'Qualified', 'Proposal sent', 'Won', 'Lost']);

    $connected = stageOf($this->brand->getKey(), 'Connected');
    expect($connected->color)->toBe(LeadStatus::PALETTE['teal']['color'])
        ->and($connected->tint)->toBe(LeadStatus::PALETTE['teal']['tint'])
        ->and($connected->slug)->toBe('connected')
        ->and($connected->isFixed())->toBeFalse();

    expect(Activity::query()->where('event', 'pipeline.stage_added')->sole()->description)->toBe('Pipeline stage added: Connected, after Contacted');
});

it('shows a new stage on the Pipeline, on the lead and lets a lead move into it', function (): void {
    $stage = app(ChangePipelineStages::class)->add($this->brand, 'Connected', 'teal', stageOf($this->brand->getKey(), 'Contacted'), $this->admin);
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Linda Rose']);

    app(ChangeLeadStatus::class)->handle($lead, $stage, $this->agent);
    expect($lead->fresh()->status_id)->toBe($stage->getKey());

    Livewire::actingAs($this->agent)->test(Pipeline::class, ['brand' => $this->brand])
        ->assertSeeInOrder(['Contacted', 'Connected', 'Qualified'])
        ->assertSee('Linda Rose')
        ->assertDontSee('Add, rename, colour or remove');

    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSeeInOrder(['Contacted', 'Connected', 'Qualified']);

    // The other brand's board never has it.
    inBrand($this->other);
    Livewire::actingAs($this->admin)->test(Pipeline::class, ['brand' => $this->other])->assertDontSee('Connected');
});

it('keeps Agents and other brands\' Managers out', function (): void {
    $this->actingAs($this->agent)->get('/b/melbourne/leads/stages')->assertForbidden();
    $this->flushSession();

    $stranger = managerOf(department($this->other, ['name' => 'UK Sales']));
    $this->actingAs($stranger)->get('/b/melbourne/leads/stages')->assertNotFound();
    $this->flushSession();

    $this->actingAs($this->manager)->get('/b/melbourne/leads/stages')->assertOk()->assertSee('Pipeline stages');
    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/melbourne/leads/pipeline')->assertOk()->assertSee('/b/melbourne/leads/stages', false);
    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/melbourne/leads/pipeline')->assertOk()->assertDontSee('/b/melbourne/leads/stages', false);

    // A stage of another brand cannot be reached from this one.
    $foreign = stageOf($this->other->getKey(), 'Qualified');
    Livewire::actingAs($this->admin)->test(PipelineStages::class, ['brand' => $this->brand])
        ->call('edit', $foreign->getKey())
        ->assertNotFound();
});

it('never removes a stage that has leads, the Bin included, and removes an empty one', function (): void {
    $actions = app(ChangePipelineStages::class);
    $stage = $actions->add($this->brand, 'Connected', 'teal', stageOf($this->brand->getKey(), 'Contacted'), $this->admin);
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'status_id' => $stage->getKey()]);

    $component = Livewire::actingAs($this->manager)->test(PipelineStages::class, ['brand' => $this->brand])
        ->call('confirmRemove', $stage->getKey())
        ->assertSet('confirm', null)
        ->assertDispatched('toast');

    // Straight to remove(), past the dialog: still refused.
    $component->call('remove', $stage->getKey());
    expect(LeadStatus::query()->whereKey($stage->getKey())->exists())->toBeTrue();

    // In the Bin it still counts: restoring the lead puts it back in this stage.
    $lead->delete();
    expect(fn () => $actions->remove($stage->fresh(), $this->manager))->toThrow(StageInUse::class, 'in the Bin');

    $lead->forceDelete();
    $component->call('confirmRemove', $stage->getKey())
        ->assertSet('confirm.method', 'remove')
        ->call('runConfirmed');

    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Contacted', 'Qualified', 'Proposal sent', 'Won', 'Lost'])
        ->and(LeadStatus::query()->forBrand($this->brand->getKey())->ordered()->pluck('sort')->all())->toBe([0, 1, 2, 3, 4, 5])
        ->and(Activity::query()->where('event', 'pipeline.stage_removed')->exists())->toBeTrue();
});

it('lets an empty default stage go, but never New, Won or Lost', function (): void {
    $proposal = stageOf($this->brand->getKey(), 'Proposal sent');

    Livewire::actingAs($this->admin)->test(PipelineStages::class, ['brand' => $this->brand])
        ->call('confirmRemove', $proposal->getKey())
        ->call('runConfirmed');

    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Contacted', 'Qualified', 'Won', 'Lost']);

    foreach (['New', 'Won', 'Lost'] as $name) {
        Livewire::actingAs($this->admin)->test(PipelineStages::class, ['brand' => $this->brand])
            ->call('confirmRemove', stageOf($this->brand->getKey(), $name)->getKey())
            ->assertForbidden();

        expect(fn () => app(ChangePipelineStages::class)->remove(stageOf($this->brand->getKey(), $name), $this->admin))->toThrow(StageInUse::class);
    }

    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Contacted', 'Qualified', 'Won', 'Lost']);
});

it('moves a stage among the open ones while New stays first and Won and Lost last', function (): void {
    $actions = app(ChangePipelineStages::class);
    $connected = $actions->add($this->brand, 'Connected', 'teal', stageOf($this->brand->getKey(), 'Contacted'));

    $component = Livewire::actingAs($this->manager)->test(PipelineStages::class, ['brand' => $this->brand]);

    $component->call('move', $connected->getKey(), 'up');
    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Connected', 'Contacted', 'Qualified', 'Proposal sent', 'Won', 'Lost']);

    // Already the first open stage: New stays above it.
    $component->call('move', $connected->getKey(), 'up');
    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Connected', 'Contacted', 'Qualified', 'Proposal sent', 'Won', 'Lost']);

    $proposal = stageOf($this->brand->getKey(), 'Proposal sent');
    $component->call('move', $proposal->getKey(), 'down');
    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Connected', 'Contacted', 'Qualified', 'Proposal sent', 'Won', 'Lost']);

    // New, Won and Lost do not move.
    $component->call('move', stageOf($this->brand->getKey(), 'Won')->getKey(), 'up');
    $component->call('move', stageOf($this->brand->getKey(), 'New')->getKey(), 'down');
    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Connected', 'Contacted', 'Qualified', 'Proposal sent', 'Won', 'Lost']);

    // A new stage after the last open one lands right before Won.
    $actions->add($this->brand, 'Negotiation', 'amber', $proposal);
    expect(stageNames($this->brand->getKey()))->toBe(['New', 'Connected', 'Contacted', 'Qualified', 'Proposal sent', 'Negotiation', 'Won', 'Lost']);
});

it('renames any stage and keeps names unique in the brand', function (): void {
    $won = stageOf($this->brand->getKey(), 'Won');

    Livewire::actingAs($this->manager)->test(PipelineStages::class, ['brand' => $this->brand])
        ->call('create')
        ->set('name', 'qualified')
        ->call('save')
        ->assertHasErrors('name')
        ->call('edit', $won->getKey())
        ->assertSet('color', 'green')
        ->set('name', 'Sold')
        ->call('save')
        ->assertHasNoErrors();

    expect($won->fresh()->name)->toBe('Sold')
        ->and($won->fresh()->is_won)->toBeTrue()
        ->and(stageNames($this->brand->getKey()))->toBe(['New', 'Contacted', 'Qualified', 'Proposal sent', 'Sold', 'Lost'])
        ->and(Activity::query()->where('event', 'pipeline.stage_changed')->sole()->description)->toBe('Pipeline stage renamed: Won → Sold');

    // The same name in another brand is fine.
    $stage = app(ChangePipelineStages::class)->add($this->other, 'Connected', 'teal', stageOf($this->other->getKey(), 'Contacted'));
    app(ChangePipelineStages::class)->add($this->brand, 'Connected', 'pink', stageOf($this->brand->getKey(), 'Contacted'));
    expect($stage->brand_id)->toBe($this->other->getKey());
});

it('puts Pipeline stages in the Settings menu of Admins and Managers only', function (): void {
    $this->actingAs($this->manager)->get('/b/melbourne/leads')->assertOk()->assertSee('Pipeline stages');
    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/melbourne/leads')->assertOk()->assertDontSee('Pipeline stages');
});
