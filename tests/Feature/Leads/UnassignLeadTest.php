<?php

declare(strict_types=1);

use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Unassign (user request, 26 Oct 2026): once a lead was given to somebody,
 * nobody could take it off them again. An Admin or the lead's Manager now
 * sends it back to its team's queue, from the assign menu or in bulk.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->other = department($this->brand, ['name' => 'Publishing']);
    $this->manager = managerOf($this->front, ['name' => 'Farah Front']);
    $this->otherManager = managerOf($this->other, ['name' => 'Paul Publishing']);
    $this->agent = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->colleague = agentOf($this->front, ['name' => 'Omar Other']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(),
        'assigned_at' => now(), 'name' => 'Sarah Lee',
    ]);
});

it('lets the lead\'s Manager send it back to the team\'s queue, with its follow-ups waiting for the next owner', function (): void {
    $task = Task::query()->create(['brand_id' => $this->brand->getKey(), 'lead_id' => $this->lead->getKey(), 'user_id' => $this->agent->getKey(), 'title' => 'Call back', 'due_at' => now()->addDay()]);

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('Unassign')
        ->call('unassign')
        ->assertDispatched('toast', title: 'Unassigned', body: 'Back in the queue: nobody owns it now.');

    $lead = $this->lead->fresh();

    expect($lead->assigned_to)->toBeNull()
        ->and($lead->assigned_at)->toBeNull()
        ->and($lead->department_id)->toBe($this->front->getKey())
        ->and($task->fresh()->user_id)->toBeNull()
        ->and($lead->activities()->where('event', 'leads.unassigned')->exists())->toBeTrue()
        ->and(Lead::query()->unassigned()->whereKey($lead->getKey())->exists())->toBeTrue();

    // Whoever gets the lead next gets its open follow-up too.
    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->call('quickAssign', $this->colleague->getKey());

    expect($task->fresh()->user_id)->toBe($this->colleague->getKey());
});

it('refuses an Agent and the Manager of another team', function (): void {
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('unassign')
        ->assertForbidden();

    Livewire::actingAs($this->agent)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->call('quickUnassign', $this->lead->getKey())
        ->assertForbidden();

    $this->flushSession();

    // Paul runs Publishing: Sarah's lead is not his to take back, alone or in bulk.
    Livewire::actingAs($this->otherManager)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('selected', [$this->lead->getKey()])
        ->set('bulkAssignTo', '0')
        ->call('bulkAssign');

    expect($this->lead->fresh()->assigned_to)->toBe($this->agent->getKey());
});

it('lets an Admin unassign from the list and undo it, and a Manager unassign several leads at once', function (): void {
    $list = Livewire::actingAs($this->admin)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->call('quickUnassign', $this->lead->getKey())
        ->assertDispatched('toast', title: 'Sarah Lee unassigned');

    expect($this->lead->fresh()->assigned_to)->toBeNull();

    $list->call('undoLeadChange', $this->lead->getKey(), 'assign', $this->agent->getKey(), (string) Str::uuid());

    expect($this->lead->fresh()->assigned_to)->toBe($this->agent->getKey());

    $more = Lead::factory()->count(2)->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->colleague->getKey()]);

    Livewire::actingAs($this->manager)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->assertSee('Nobody: unassign, back to the queue')
        ->set('selected', [$this->lead->getKey(), ...$more->modelKeys()])
        ->set('bulkAssignTo', '0')
        ->call('bulkAssign')
        ->assertSet('selected', [])
        ->assertDispatched('toast', title: '3 leads unassigned');

    expect(Lead::query()->whereKey([$this->lead->getKey(), ...$more->modelKeys()])->whereNotNull('assigned_to')->count())->toBe(0);
});
