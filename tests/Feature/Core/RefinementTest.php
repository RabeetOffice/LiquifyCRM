<?php

declare(strict_types=1);

use App\Modules\Auth\Livewire\Admin\PeopleIndex;
use App\Modules\Auth\Livewire\Admin\PersonForm;
use App\Modules\Auth\Livewire\Profile;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Livewire\Admin\BrandWizard;
use App\Modules\Core\Enums\AssignmentMode;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Livewire\NotificationBell;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Actions\AutoAssignLead;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Actions\SendLeadToDepartment;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Livewire\App\DistributePage;
use App\Modules\Sales\Models\Target;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The 9 October refinement: Admins hold the same control as Super Admins and
 * add Admins, Managers and Agents from one People screen; people carry a
 * designation; a department can share its leads round robin; the brand
 * wizard fills the name and address from the website link; notifications
 * announce themselves; the chosen theme survives navigation.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department, ['name' => 'Grace Manager']);
    $this->first = agentOf($this->department, ['name' => 'Aisha Agent']);
    $this->second = agentOf($this->department, ['name' => 'Omar Agent']);
    $this->admin = admin();

    inBrand($this->brand);
});

// ------------------------------------------------------------------ people

it('lets an Admin add a Manager with a designation, a department and a target from the admin area', function (): void {
    inBrand(null);

    Livewire::actingAs($this->admin)
        ->test(PersonForm::class)
        ->set('kind', 'manager')
        ->set('name', 'Sana Malik')
        ->set('designation', 'Sales Team Lead')
        ->set('email', 'Sana@Example.com')
        ->set('phone', '+447700900123')
        ->set('password', 'a-long-password-1')
        ->call('save')
        ->assertHasErrors('brandId')
        ->set('brandId', (string) $this->brand->getKey())
        ->set('departmentId', (string) $this->department->getKey())
        ->set('targetRevenue', '8000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.people.index'));

    $person = User::query()->where('email', 'sana@example.com')->sole();

    expect($person->designation)->toBe('Sales Team Lead')
        ->and($person->isGlobalAdmin())->toBeFalse()
        ->and($person->brandRole($this->brand))->toBe(BrandRole::Manager)
        ->and($person->managesDepartment($this->department))->toBeTrue()
        ->and((float) Target::query()->acrossBrands()->where('targetable_type', Target::USER)->where('targetable_id', $person->getKey())->value('revenue_target'))->toBe(8000.0);
});

it('lets an Admin add another Admin, and lists everyone by role on the People screen', function (): void {
    inBrand(null);

    Livewire::actingAs($this->admin)
        ->test(PersonForm::class)
        ->set('kind', 'admin')
        ->set('name', 'Bilal Admin')
        ->set('email', 'bilal@example.com')
        ->set('password', 'short-1')
        ->call('save')
        ->assertHasErrors('password')
        ->set('password', 'a-long-password-1')
        ->call('save')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'bilal@example.com')->sole()->isAdmin())->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(PeopleIndex::class)
        ->assertSee('Bilal Admin')
        ->assertSee('Grace Manager')
        ->assertSee('Aisha Agent')
        ->set('tab', 'managers')
        ->assertSee('Grace Manager')
        ->assertDontSee('Aisha Agent')
        ->assertDontSee('Bilal Admin')
        ->set('tab', 'admins')
        ->assertSee('Bilal Admin')
        ->assertDontSee('Grace Manager');

    $this->actingAs($this->manager)->get('/admin/people/create')->assertForbidden();
});

it('shows a person their profile with designation, role and brand, and saves the designation', function (): void {
    $this->first->forceFill(['designation' => 'Sales Executive'])->save();

    $this->actingAs($this->first)->get('/profile')
        ->assertOk()
        ->assertSee('Aisha Agent')
        ->assertSee('Sales Executive')
        ->assertSee('Agent · UK Publishing House')
        ->assertSee('Sales Team A')
        ->assertSee('Open leads');

    Livewire::actingAs($this->first)
        ->test(Profile::class)
        ->set('designation', 'Senior Sales Executive')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->first->refresh()->designation)->toBe('Senior Sales Executive');
});

// ------------------------------------------------------------- round robin

it('leaves leads in the queue when a department shares by hand', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);

    app(SendLeadToDepartment::class)->handle($lead, $this->department, $this->admin);

    expect($lead->refresh()->department_id)->toBe($this->department->getKey())
        ->and($lead->assigned_to)->toBeNull();
});

it('gives each new lead to the next Agent in turn when a department shares round robin', function (): void {
    $this->department->update(['assignment_mode' => AssignmentMode::RoundRobin]);

    $leads = Lead::factory()->count(3)->create(['brand_id' => $this->brand->getKey()]);

    foreach ($leads as $lead) {
        app(SendLeadToDepartment::class)->handle($lead, $this->department, $this->admin);
    }

    // First, second, then back to the first.
    expect($leads[0]->refresh()->assigned_to)->toBe($this->first->getKey())
        ->and($leads[1]->refresh()->assigned_to)->toBe($this->second->getKey())
        ->and($leads[2]->refresh()->assigned_to)->toBe($this->first->getKey())
        ->and($this->department->refresh()->last_assigned_user_id)->toBe($this->first->getKey());

    Notification::assertSentTo($this->second, AppNotification::class);

    // A lead that arrives straight in the department is shared the same way; a suspended Agent is skipped.
    $this->second->forceFill(['status' => 'suspended'])->save();

    $created = app(CreateLead::class)->handle($this->brand, [
        'source_type' => LeadSource::Manual, 'name' => 'Nadia Noor', 'email' => 'nadia@example.com', 'phone' => null,
        'department_id' => $this->department->getKey(),
    ], $this->admin);

    expect($created->lead->refresh()->assigned_to)->toBe($this->first->getKey());
});

it('lets a Manager turn round robin on for their own department and share the waiting leads', function (): void {
    $other = department($this->brand, ['name' => 'Consultants']);
    $waiting = Lead::factory()->count(4)->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey()]);

    Livewire::actingAs($this->manager)
        ->test(DistributePage::class, ['brand' => $this->brand])
        ->assertSee('How new leads are shared')
        ->assertSee('Sales Team A')
        ->assertDontSee('Consultants')
        ->call('setSharing', $other->getKey(), 'round_robin')
        ->assertForbidden();

    Livewire::actingAs($this->manager)
        ->test(DistributePage::class, ['brand' => $this->brand])
        ->call('setSharing', $this->department->getKey(), 'round_robin')
        ->assertSee('Each new lead goes to the next of 2 Agents')
        ->call('shareWaiting', $this->department->getKey());

    expect($this->department->refresh()->assignment_mode)->toBe(AssignmentMode::RoundRobin)
        ->and($other->refresh()->assignment_mode)->toBe(AssignmentMode::Manual)
        ->and(Lead::query()->whereIn('id', $waiting->modelKeys())->where('assigned_to', $this->first->getKey())->count())->toBe(2)
        ->and(Lead::query()->whereIn('id', $waiting->modelKeys())->where('assigned_to', $this->second->getKey())->count())->toBe(2)
        ->and(app(AutoAssignLead::class)->spread($other, [1]))->toBe([]);
});

// ------------------------------------------------------------ brand wizard

it('fills the brand name and address from the website link', function (): void {
    inBrand(null);

    Http::fake([
        'riverbooks.test/*' => Http::response('<html><head><title>River Books | Independent publisher</title></head></html>'),
        'unreachable.test/*' => Http::response('', 500),
    ]);

    Livewire::actingAs($this->admin)
        ->test(BrandWizard::class)
        ->set('website', 'riverbooks.test')
        ->assertSet('website', 'https://riverbooks.test')
        ->assertSet('name', 'River Books')
        ->assertSet('slug', 'river-books')
        ->assertSee('filled in from the website')
        // A name typed by hand is never replaced by a later link.
        ->set('name', 'My Own Name')
        ->set('website', 'unreachable.test')
        ->assertSet('name', 'My Own Name');

    // When the website cannot be read, its address gives a starting name; a taken address gets a number.
    Livewire::actingAs($this->admin)
        ->test(BrandWizard::class)
        ->set('website', 'https://www.ukph.test')
        ->assertSet('name', 'Ukph')
        ->assertSet('slug', 'ukph-2');
});

// ----------------------------------------------------------- notifications

it('announces a notification that arrived since the last check', function (): void {

    $bell = Livewire::actingAs($this->first)->test(NotificationBell::class)->assertSet('seen', 0);

    $bell->call('tick')->assertNotDispatched('toast');

    // Notifications are faked in this file, so the row is written the way the database channel writes it.
    $this->first->notifications()->create(['id' => (string) Str::uuid(), 'type' => AppNotification::class, 'data' => ['title' => 'New lead: Sarah Lee', 'body' => 'Assigned to you.']]);

    $bell->call('tick')
        ->assertDispatched('toast')
        ->assertSet('seen', 1)
        ->call('tick')
        ->assertNotDispatched('toast');
});

// ------------------------------------------------------------------- theme

it('renders the page in the saved theme so dark mode survives navigation', function (): void {
    inBrand(null);

    $_COOKIE['lq_theme'] = 'dark';
    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('<html lang="en" class="dark">', false);

    $_COOKIE['lq_theme'] = 'light';
    $this->get('/admin')->assertOk()->assertDontSee('class="dark"', false);

    unset($_COOKIE['lq_theme']);
});
