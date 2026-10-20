<?php

declare(strict_types=1);

use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Livewire\App\LeadForm;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Newest leads on top (user request, 20 Oct 2026), an older enquiry added by
 * hand with its own date, and times typed and shown in the person's own zone.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department);
    $this->admin = admin();

    inBrand($this->brand);

    $lead = fn (string $name, string $created, string $activity): Lead => Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'name' => $name,
        'created_at' => $created, 'last_activity_at' => $activity,
    ]);

    $this->old = $lead('Old Enquiry', '2026-10-01 09:00:00', '2026-10-19 09:00:00');
    $this->middle = $lead('Middle Enquiry', '2026-10-10 09:00:00', '2026-10-11 09:00:00');
    $this->fresh = $lead('Fresh Enquiry', '2026-10-20 11:00:00', '2026-10-20 11:00:00');
});

it('puts the newest lead on top of every view, the Unassigned one too, and sorts as asked', function (): void {
    $list = Livewire::actingAs($this->admin)->test(LeadsIndex::class, ['brand' => $this->brand]);

    $list->assertSeeInOrder(['Fresh Enquiry', 'Middle Enquiry', 'Old Enquiry'])
        ->set('tab', 'unassigned')->assertSeeInOrder(['Fresh Enquiry', 'Middle Enquiry', 'Old Enquiry'])
        ->set('order', 'oldest')->assertSeeInOrder(['Old Enquiry', 'Middle Enquiry', 'Fresh Enquiry'])
        ->set('order', 'activity')->assertSeeInOrder(['Fresh Enquiry', 'Old Enquiry', 'Middle Enquiry'])
        ->set('order', 'nonsense')->assertSet('order', '')->assertSeeInOrder(['Fresh Enquiry', 'Middle Enquiry', 'Old Enquiry']);

    // A column heading takes over, dates newest first, and the Sort choice says so.
    $list->call('sortBy', 'created_at')->assertSet('direction', 'desc')->assertSee('By column')
        ->call('sortBy', 'created_at')->assertSeeInOrder(['Old Enquiry', 'Middle Enquiry', 'Fresh Enquiry']);
});

it('orders the pipeline newest first, or by recent activity', function (): void {
    Livewire::actingAs($this->admin)
        ->test(Pipeline::class, ['brand' => $this->brand])
        ->assertSeeInOrder(['Fresh Enquiry', 'Middle Enquiry', 'Old Enquiry'])
        ->set('order', 'activity')
        ->assertSeeInOrder(['Fresh Enquiry', 'Old Enquiry', 'Middle Enquiry']);
});

it('adds an older enquiry by hand with the day it came in, read in the person\'s own time zone', function (): void {
    $this->admin->forceFill(['timezone' => 'Asia/Karachi'])->save();

    Livewire::actingAs($this->admin)
        ->test(LeadForm::class, ['brand' => $this->brand])
        ->assertSet('receivedAt', '2026-10-20T17:00')
        ->set('name', 'Walk-in From Last Week')
        ->set('receivedAt', '2026-10-13T15:30')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->where('name', 'Walk-in From Last Week')->firstOrFail();

    // 15:30 in Karachi is 10:30 UTC; the lead counts on its own day but was just added, so it leads "Recently added".
    expect($lead->created_at->toDateTimeString())->toBe('2026-10-13 10:30:00')
        ->and($lead->last_activity_at->toDateTimeString())->toBe('2026-10-20 12:00:00');

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.created', 'subject_id' => $lead->getKey(), 'description' => 'Lead created from Added by hand, for an enquiry from 13 Oct 2026, 15:30']);

    Livewire::actingAs($this->admin)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->assertSeeInOrder(['Fresh Enquiry', 'Walk-in From Last Week', 'Middle Enquiry', 'Old Enquiry'])
        ->set('order', 'added')
        ->assertSeeInOrder(['Walk-in From Last Week', 'Fresh Enquiry']);
});

it('keeps a lead added "now" at the present moment and refuses a date still to come', function (): void {
    Livewire::actingAs($this->manager)
        ->test(LeadForm::class, ['brand' => $this->brand])
        ->set('name', 'Caller Today')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->where('name', 'Caller Today')->firstOrFail()->created_at->toDateTimeString())->toBe('2026-10-20 12:00:00');

    // A form that took ten minutes to fill in, its date untouched, is still "now", not ten minutes ago.
    $slow = Livewire::actingAs($this->manager)->test(LeadForm::class, ['brand' => $this->brand])->set('name', 'Slow Typist');
    $this->travel(10)->minutes();
    $slow->call('save')->assertHasNoErrors();

    $lead = Lead::query()->where('name', 'Slow Typist')->firstOrFail();
    expect($lead->created_at->toDateTimeString())->toBe('2026-10-20 12:10:00');
    $this->assertDatabaseHas('activity_log', ['event' => 'leads.created', 'subject_id' => $lead->getKey(), 'description' => 'Lead created from Added by hand']);

    Livewire::actingAs($this->manager)
        ->test(LeadForm::class, ['brand' => $this->brand])
        ->set('name', 'From The Future')
        ->set('receivedAt', '2026-10-22T09:00')
        ->call('save')
        ->assertHasErrors('receivedAt');

    expect(Lead::query()->where('name', 'From The Future')->exists())->toBeFalse();
});

it('uses the computer\'s time zone when the Profile leaves it on Automatic, and the Profile\'s when one is chosen', function (): void {
    request()->cookies->set(ViewerTime::COOKIE, 'Australia/Sydney');
    $this->actingAs($this->manager);

    expect(ViewerTime::zone())->toBe('Australia/Sydney')
        ->and(ViewerTime::now()->format('H:i'))->toBe('23:00');

    $this->manager->forceFill(['timezone' => 'America/New_York'])->save();
    expect(ViewerTime::zone())->toBe('America/New_York');

    // Nonsense from a cookie is ignored.
    $this->manager->forceFill(['timezone' => null])->save();
    request()->cookies->set(ViewerTime::COOKIE, 'Mars/Olympus');
    expect(ViewerTime::zone())->toBe('Europe/London');
});

it('sets a follow-up at the time the person typed in their own zone, and counts their own today', function (): void {
    $this->manager->forceFill(['timezone' => 'Australia/Sydney'])->save();

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $this->fresh->getKey()])
        ->call('openFollowUp')
        ->assertSet('followUpAt', '2026-10-21T10:00')
        ->set('followUpAt', '2026-10-21T09:15')
        ->call('scheduleFollowUp')
        ->assertHasNoErrors();

    // 09:15 on the 21st in Sydney (UTC+11) is 22:15 on the 20th in UTC: today in London, tomorrow in Sydney.
    $task = Task::query()->where('lead_id', $this->fresh->getKey())->firstOrFail();
    expect($task->due_at->toDateTimeString())->toBe('2026-10-20 22:15:00');

    $this->actingAs($this->manager);
    expect(Task::query()->dueToday()->count())->toBe(0);

    // Two hours later it is past midnight in Sydney: now it is today's.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 14:00:00', 'UTC'));
    expect(Task::query()->dueToday()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $this->manager->forceFill(['timezone' => 'Europe/London'])->save();
    expect(Task::query()->dueToday()->count())->toBe(1);
});
