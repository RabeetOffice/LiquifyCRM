<?php

declare(strict_types=1);

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Livewire\App\FollowUps;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Follow-ups as a calendar, and reminders 10, 5 and 1 minutes before
 * (user request, 20 Oct 2026).
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->agent = agentOf($this->department, ['name' => 'Bill Graham', 'timezone' => 'Asia/Karachi']);
    $this->other = agentOf($this->department, ['name' => 'Omar Other']);

    inBrand($this->brand);

    $this->lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Sarah Lee']);
    $this->task = fn (string $title, string $dueUtc, ?int $owner = null): Task => Task::query()->create([
        'brand_id' => $this->brand->getKey(), 'lead_id' => $this->lead->getKey(), 'user_id' => $owner ?? $this->agent->getKey(), 'title' => $title, 'due_at' => $dueUtc,
    ]);
});

it('shows the week as a diary in the person\'s own time, and the month with a few a day', function (): void {
    ($this->task)('Call back', '2026-10-21 09:30:00');      // Wednesday 14:30 in Karachi
    ($this->task)('Send the proposal', '2026-10-21 09:45:00'); // close to the first: side by side
    ($this->task)('Next month', '2026-11-03 06:00:00');

    $calendar = Livewire::actingAs($this->agent)
        ->test(FollowUps::class, ['brand' => $this->brand])
        ->assertSet('view', 'week')
        ->assertSee('19 – 25 October 2026')
        ->assertSeeInOrder(['Wed', '21'])
        ->assertSee('14:30')
        ->assertSee('Call back')
        ->assertSee('Sarah Lee')
        ->assertSee('calc(1 * 100% / 2 + 2px)', false)
        ->assertDontSee('Next month');

    $calendar->call('go', 1)->assertSee('26 Oct – 1 Nov 2026');

    $calendar->set('view', 'month')->call('today')
        ->assertSee('October 2026')
        ->assertSee('Call back', false)
        ->call('go', 1)
        ->assertSee('November 2026')
        ->assertSee('11:00')
        ->call('showWeek', '2026-11-03')
        ->assertSet('view', 'week')
        ->assertSee('2 – 8 November 2026');
});

it('moves a follow-up by dragging it to another day and time, in the person\'s own time', function (): void {
    $task = ($this->task)('Call back', '2026-10-21 09:30:00');

    Livewire::actingAs($this->agent)
        ->test(FollowUps::class, ['brand' => $this->brand])
        ->call('move', $task->getKey(), '2026-10-23', 16 * 60 + 7) // dropped at 16:07 on Friday: 16:00 there
        ->assertHasNoErrors();

    expect($task->fresh()->due_at->toDateTimeString())->toBe('2026-10-23 11:00:00')
        ->and($this->lead->fresh()->next_follow_up_at->toDateTimeString())->toBe('2026-10-23 11:00:00');

    // In the month a day changes and the time stays.
    Livewire::actingAs($this->agent)->test(FollowUps::class, ['brand' => $this->brand])->call('move', $task->getKey(), '2026-10-27');
    expect($task->fresh()->due_at->toDateTimeString())->toBe('2026-10-27 11:00:00');

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.follow_up_moved', 'subject_id' => $this->lead->getKey()]);

    // Somebody else's follow-up on a lead they do not work cannot be moved.
    $foreign = Task::query()->create(['brand_id' => $this->brand->getKey(), 'lead_id' => Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => department($this->brand)->getKey()])->getKey(), 'user_id' => $this->other->getKey(), 'title' => 'Not yours', 'due_at' => '2026-10-21 10:00:00']);

    rescue(fn () => Livewire::actingAs($this->agent)->test(FollowUps::class, ['brand' => $this->brand])->call('move', $foreign->getKey(), '2026-10-22'), report: false);
    expect($foreign->fresh()->due_at->toDateTimeString())->toBe('2026-10-21 10:00:00');
});

it('reminds the owner 10, 5 and 1 minutes before, once each, in the bell only', function (): void {
    $task = ($this->task)('Call back', '2026-10-20 12:10:00');

    $this->artisan('followups:remind')->assertSuccessful();
    Notification::assertSentToTimes($this->agent, AppNotification::class, 1);
    Notification::assertSentTo($this->agent, AppNotification::class, fn (AppNotification $n, array $channels): bool => $n->title === 'Follow-up in 10 minutes: Call back'
        && str_contains($n->body, 'Sarah Lee') && $channels === ['database'] && str_contains((string) $n->url, '/leads/'.$this->lead->getKey()));

    // The same minute again sends nothing new.
    $this->artisan('followups:remind');
    Notification::assertSentToTimes($this->agent, AppNotification::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:05:00', 'UTC'));
    $this->artisan('followups:remind');
    Notification::assertSentTo($this->agent, AppNotification::class, fn (AppNotification $n): bool => $n->title === 'Follow-up in 5 minutes: Call back');

    // A minute the scheduler missed is caught up: at 12:09:20 the 1-minute reminder still goes.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:09:20', 'UTC'));
    $this->artisan('followups:remind');
    Notification::assertSentTo($this->agent, AppNotification::class, fn (AppNotification $n): bool => $n->title === 'Follow-up in 1 minute: Call back' && $n->status === 'warning');
    Notification::assertSentToTimes($this->agent, AppNotification::class, 3);

    // Moved to later: reminded again for the new time. Done ones are not.
    $task->forceFill(['due_at' => '2026-10-20 12:15:00'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:05:00', 'UTC'));
    $this->artisan('followups:remind');
    Notification::assertSentToTimes($this->agent, AppNotification::class, 4);

    $task->forceFill(['done_at' => now()])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:10:00', 'UTC'));
    $this->artisan('followups:remind');
    Notification::assertSentToTimes($this->agent, AppNotification::class, 4);
});

it('does not remind for an archived lead, nor anybody about a follow-up that is not theirs', function (): void {
    ($this->task)('Call back', '2026-10-20 12:08:00');
    $this->lead->forceFill(['archived_at' => now()])->save();

    $this->artisan('followups:remind');

    Notification::assertNothingSent();
});
