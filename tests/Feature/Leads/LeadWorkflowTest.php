<?php

declare(strict_types=1);

use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Actions\RevealLeadContact;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Actions\SendLeadToDepartment;
use App\Modules\Leads\Exceptions\RevealLimitReached;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\ContactMask;
use App\Modules\Leads\Support\ContactVisibility;
use Illuminate\Support\Facades\Notification;

/*
 * Week 2 lead pipeline: create, de-duplicate, distribute, assign, move
 * along the pipeline, mask and reveal contacts, follow up, log everything.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin();

    inBrand($this->brand);
});

it('gives every brand the six default statuses', function (): void {
    expect(LeadStatus::query()->forBrand($this->brand)->ordered()->pluck('name')->all())
        ->toBe(['New', 'Contacted', 'Qualified', 'Proposal sent', 'Won', 'Lost'])
        ->and(LeadStatus::defaultFor($this->brand)?->name)->toBe('New');
});

it('creates a manual lead in New / Unassigned with a normalised phone and tells the Admins', function (): void {
    $result = app(CreateLead::class)->handle($this->brand, [
        'name' => 'Sarah Lee',
        'email' => 'Sarah@Example.com',
        'phone' => '07911 123456',
        'message' => 'I want to publish my first novel',
    ], $this->admin);

    expect($result->wasMerged)->toBeFalse()
        ->and($result->lead->email)->toBe('sarah@example.com')
        ->and($result->lead->phone)->toBe('+447911123456')
        ->and($result->lead->status->is_default)->toBeTrue()
        ->and($result->lead->assigned_to)->toBeNull();

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.created', 'subject_id' => $result->lead->getKey()]);
    // The bell at once; the email comes with the summary every three hours (leads:digest).
    Notification::assertSentTo($this->admin, AppNotification::class, fn (AppNotification $notification, array $channels): bool => $notification->title === 'New lead: Sarah Lee' && $channels === ['database']);
});

it('adds a repeat enquiry to the existing open lead instead of creating a second one', function (): void {
    $first = app(CreateLead::class)->handle($this->brand, ['name' => 'Sarah Lee', 'email' => 'sarah@example.com'], $this->admin);
    $second = app(CreateLead::class)->handle($this->brand, ['name' => 'S. Lee', 'email' => 'SARAH@example.com', 'message' => 'Still interested'], $this->admin);

    expect($second->wasMerged)->toBeTrue()
        ->and($second->lead->is($first->lead))->toBeTrue()
        ->and(Lead::query()->count())->toBe(1);

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.repeat_enquiry', 'subject_id' => $first->lead->getKey()]);
});

it('shows Admins everything, Managers their department and front sales its team, each working only their own', function (): void {
    $queued = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);
    $inDepartment = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey()]);
    $mine = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $elsewhere = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => department($this->brand, ['name' => 'Publishing'])->getKey()]);
    $upseller = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));
    $ids = fn ($query): array => $query->pluck('id')->sort()->values()->all();

    // Seeing: front sales (Manager and Agent of a sales team) sees the team's leads and those no team has yet.
    expect(Lead::query()->visibleTo($this->admin)->count())->toBe(4)
        ->and($ids(Lead::query()->visibleTo($this->manager)))->toBe([$queued->getKey(), $inDepartment->getKey(), $mine->getKey()])
        ->and($ids(Lead::query()->visibleTo($this->agent)))->toBe([$queued->getKey(), $inDepartment->getKey(), $mine->getKey()])
        ->and(Lead::query()->visibleTo($upseller)->count())->toBe(0)
        ->and($this->agent->can('view', $inDepartment))->toBeTrue()
        ->and($this->agent->can('view', $elsewhere))->toBeFalse()
        ->and($this->manager->can('view', $queued))->toBeTrue()
        ->and($this->manager->can('view', $elsewhere))->toBeFalse()
        ->and($upseller->can('view', $queued))->toBeFalse()
        ->and($this->admin->can('view', $elsewhere))->toBeTrue();

    // Working: still only their own (and the Manager their department's), so their numbers stay theirs.
    expect($ids(Lead::query()->workedBy($this->manager)))->toBe([$inDepartment->getKey(), $mine->getKey()])
        ->and($ids(Lead::query()->workedBy($this->agent)))->toBe([$mine->getKey()])
        ->and($this->agent->can('update', $mine))->toBeTrue()
        ->and($this->agent->can('update', $inDepartment))->toBeFalse()
        ->and($this->agent->can('reveal', $inDepartment))->toBeFalse()
        ->and($this->agent->can('recordSale', $inDepartment))->toBeFalse()
        ->and($this->agent->can('assign', $inDepartment))->toBeFalse();
});

it('sends a lead to a department and then to an Agent, logging and notifying each step', function (): void {
    $lead = app(CreateLead::class)->handle($this->brand, ['name' => 'Tom Walker'], $this->admin)->lead;

    app(SendLeadToDepartment::class)->handle($lead, $this->department, $this->admin);

    expect($lead->fresh()->department_id)->toBe($this->department->getKey());
    Notification::assertSentTo($this->manager, AppNotification::class);

    expect($this->manager->can('assign', $lead->fresh()))->toBeTrue()
        ->and($this->agent->can('assign', $lead->fresh()))->toBeFalse();

    app(AssignLead::class)->handle($lead->fresh(), $this->agent, $this->manager);
    $lead->refresh();

    expect($lead->assigned_to)->toBe($this->agent->getKey())
        ->and($lead->assigned_at)->not->toBeNull();

    Notification::assertSentTo($this->agent, AppNotification::class);
    $this->assertDatabaseHas('activity_log', ['event' => 'leads.assigned', 'subject_id' => $lead->getKey()]);
});

it('moves a lead along the pipeline and stamps the first contact', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);
    $contacted = LeadStatus::query()->forBrand($this->brand)->where('slug', 'contacted')->firstOrFail();

    app(ChangeLeadStatus::class)->handle($lead, $contacted, $this->admin);
    $lead->refresh();

    expect($lead->status->slug)->toBe('contacted')
        ->and($lead->first_contacted_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.status_changed', 'subject_id' => $lead->getKey()]);
});

it('masks contact details for Agents until they reveal, logs each reveal and enforces the hourly limit', function (): void {
    $this->brand->forceFill(['settings' => ['reveal_limit_per_hour' => 2]])->save();

    $leads = Lead::factory()->count(3)->create([
        'brand_id' => $this->brand->getKey(),
        'department_id' => $this->department->getKey(),
        'assigned_to' => $this->agent->getKey(),
        'phone' => '+447700900123',
        'email' => 'sarah@example.com',
    ]);

    expect(ContactMask::phone('+447700900123'))->toBe('+44 77•• •••123')
        ->and(ContactMask::email('sarah@example.com'))->toBe('s•••@example.com');

    $visibility = app(ContactVisibility::class);

    expect($visibility->canSeeFull($this->agent, $leads[0]))->toBeFalse()
        ->and($visibility->canSeeFull($this->manager, $leads[0]))->toBeTrue()
        ->and($visibility->canSeeFull($this->admin, $leads[0]))->toBeTrue();

    app(RevealLeadContact::class)->handle($leads[0], $this->agent);
    app(RevealLeadContact::class)->handle($leads[1], $this->agent);

    expect($visibility->canSeeFull($this->agent, $leads[0]))->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['event' => 'leads.contact_revealed', 'subject_id' => $leads[0]->getKey(), 'causer_id' => $this->agent->getKey()]);

    expect(fn () => app(RevealLeadContact::class)->handle($leads[2], $this->agent))->toThrow(RevealLimitReached::class);
});

it('schedules follow-ups, adds notes and clears the follow-up when it is done', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'assigned_to' => $this->agent->getKey()]);

    $task = app(ScheduleFollowUp::class)->handle($lead, now()->addDay(), 'Call back', $this->agent);
    app(AddLeadNote::class)->handle($lead, 'Left a voicemail.', $this->agent);

    expect($lead->fresh()->next_follow_up_at)->not->toBeNull()
        ->and($task->user_id)->toBe($this->agent->getKey())
        ->and($lead->notes()->count())->toBe(1);

    app(ScheduleFollowUp::class)->complete($task, $this->agent);

    expect($lead->fresh()->next_follow_up_at)->toBeNull()
        ->and($task->fresh()->done_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.note_added', 'subject_id' => $lead->getKey()]);
    $this->assertDatabaseHas('activity_log', ['event' => 'leads.follow_up_done', 'subject_id' => $lead->getKey()]);
});

it("returns an Agent's open leads to the department queue when they are removed", function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);

    app(RemoveMemberFromBrand::class)->handle($this->agent->membershipFor($this->brand), $this->admin);
    $lead->refresh();

    expect($lead->assigned_to)->toBeNull()
        ->and($lead->department_id)->toBe($this->department->getKey());

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.returned_to_queue']);
});
