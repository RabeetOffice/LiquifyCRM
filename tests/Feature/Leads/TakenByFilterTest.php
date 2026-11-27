<?php

declare(strict_types=1);

use App\Modules\Core\Navigation\Menu;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\TakeLead;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\LeadsTaken;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadTakes;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * "Taken by" (user request, 27 Nov 2026): Admins and Managers see which leads, and how many,
 * one person took for themselves with Take.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Maria Manager']);
    $this->ali = agentOf($this->front, ['name' => 'Ali Khan']);
    $this->sara = agentOf($this->front, ['name' => 'Sara Malik']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = fn (string $name): Lead => Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => $name]);
});

it('shows an Admin and a Manager the leads one person took, and how many', function (): void {
    $kept = ($this->lead)('Dean Williams');
    $passed = ($this->lead)('Emma Stone');
    $given = ($this->lead)('Given Lead');
    ($this->lead)('Free Lead');

    app(TakeLead::class)->handle($kept, $this->ali);
    app(TakeLead::class)->handle($passed, $this->ali);
    // Later given to Sara by the Manager: still one Ali took.
    app(AssignLead::class)->handle($passed->fresh(), $this->sara, $this->manager);
    // Given to Ali by the Manager: not a take.
    app(AssignLead::class)->handle($given, $this->ali, $this->manager);

    foreach ([$this->admin, $this->manager] as $viewer) {
        $list = Livewire::actingAs($viewer)
            ->test(LeadsIndex::class, ['brand' => $this->brand])
            ->set('takenBy', (string) $this->ali->getKey())
            ->assertSee('Dean Williams')
            ->assertSee('Emma Stone')
            ->assertDontSee('Given Lead')
            ->assertDontSee('Free Lead')
            ->assertSee('Taken by Ali Khan');

        expect($list->viewData('takes'))->toMatchArray(['name' => 'Ali Khan', 'taken' => 2, 'theirs' => 1, 'won' => 0]);
    }
});

it('does not count a take given back with Undo', function (): void {
    $lead = ($this->lead)('Dean Williams');

    app(TakeLead::class)->handle($lead, $this->ali);
    app(AssignLead::class)->handle($lead->fresh(), null, $this->ali);

    expect(Lead::query()->whereIn('id', LeadTakes::leadIds($this->ali, (int) $this->brand->getKey()))->count())->toBe(0);

    // Taken again for good, it counts once.
    app(TakeLead::class)->handle($lead->fresh(), $this->ali);
    $this->travel(LeadTakes::UNDO_MINUTES + 5)->minutes();

    expect(Lead::query()->whereIn('id', LeadTakes::leadIds($this->ali, (int) $this->brand->getKey()))->count())->toBe(1);
});

it('narrows the takes to a period', function (): void {
    $this->travelTo(now()->startOfMonth()->subDays(3));
    app(TakeLead::class)->handle(($this->lead)('Old Take'), $this->ali);
    $this->travelBack();
    app(TakeLead::class)->handle(($this->lead)('New Take'), $this->ali);

    $list = Livewire::actingAs($this->admin)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('takenBy', (string) $this->ali->getKey())
        ->set('takenIn', 'month')
        ->assertSee('New Take')
        ->assertDontSee('Old Take');

    expect($list->viewData('takes')['taken'])->toBe(1);

    $list->set('takenIn', 'last-month')->assertSee('Old Take')->assertDontSee('New Take');
    $list->set('takenIn', '')->assertSee('Old Take')->assertSee('New Take');

    // One chip for both; removing it clears the period too.
    $list->set('takenIn', 'month')->call('removeFilter', 'takenBy')->assertSet('takenBy', '')->assertSet('takenIn', '');
});

it('never lets an Agent look at a colleague\'s takes', function (): void {
    app(TakeLead::class)->handle(($this->lead)('Dean Williams'), $this->ali);
    ($this->lead)('Free Lead');

    $list = Livewire::actingAs($this->sara)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('takenBy', (string) $this->ali->getKey())
        ->assertSee('Free Lead')
        ->assertDontSee('Taken by Ali Khan');

    expect($list->viewData('takes'))->toBeNull();
});

it('keeps a Manager to the people of their own teams', function (): void {
    $other = department($this->brand, ['name' => 'Other Sales']);
    $stranger = agentOf($other, ['name' => 'Omar Other']);
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $other->getKey(), 'name' => 'Other Lead']);
    app(TakeLead::class)->handle($lead, $stranger);

    $list = Livewire::actingAs($this->manager)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('takenBy', (string) $stranger->getKey());

    expect($list->viewData('takes'))->toBeNull();
});

it('lists everybody on Team › Leads taken with today, this month, last month and all time', function (): void {
    $this->travelTo(now()->startOfMonth()->subDays(3));
    app(TakeLead::class)->handle(($this->lead)('Old Take'), $this->ali);
    $this->travelBack();
    $won = ($this->lead)('New Take');
    app(TakeLead::class)->handle($won, $this->ali);
    $passed = ($this->lead)('Passed On');
    app(TakeLead::class)->handle($passed, $this->ali);
    app(AssignLead::class)->handle($passed->fresh(), $this->sara, $this->manager);

    $counts = LeadTakes::perPerson((int) $this->brand->getKey(), [(int) $this->ali->getKey(), (int) $this->sara->getKey()]);

    expect($counts[$this->ali->getKey()])->toMatchArray(['today' => 2, 'month' => 2, 'last_month' => 1, 'all' => 3, 'theirs' => 2])
        ->and($counts[$this->sara->getKey()]['all'])->toBe(0);

    foreach ([$this->admin, $this->manager] as $viewer) {
        Livewire::actingAs($viewer)
            ->test(LeadsTaken::class, ['brand' => $this->brand])
            ->assertOk()
            ->assertSee('Ali Khan')
            ->assertSee('Sara Malik')
            ->assertSee(route('brand.leads.index', ['brand' => $this->brand->slug, 'taken' => $this->ali->getKey(), 'taken_in' => 'month']))
            ->assertViewHas('totals', ['today' => 2, 'month' => 2, 'last_month' => 1, 'all' => 3]);
    }

    $this->actingAs($this->admin)->get(route('brand.leads.index', ['brand' => $this->brand->slug]))->assertSee('Leads taken');
});

it('keeps Agents out of Team › Leads taken', function (): void {
    $this->actingAs($this->ali)->get(route('brand.leads.taken', ['brand' => $this->brand->slug]))->assertForbidden();
    $this->actingAs($this->manager)->get(route('brand.leads.taken', ['brand' => $this->brand->slug]))->assertOk()->assertSee('Leads taken');
});

it('puts Leads taken inside the Performance tab of the menu, for Admins and Managers only', function (): void {
    $tab = fn ($user) => collect(Menu::for($user, $this->brand, 'brand.leads.taken'))
        ->flatMap(fn (array $group): array => $group['items'])
        ->firstWhere('label', 'Performance');

    foreach ([$this->admin, $this->manager] as $viewer) {
        expect($tab($viewer))->not->toBeNull()
            ->and($tab($viewer)['active'])->toBeTrue()
            ->and(array_column($tab($viewer)['children'], 'label'))->toContain('Leads taken');
    }

    expect($tab($this->ali))->toBeNull();
});
