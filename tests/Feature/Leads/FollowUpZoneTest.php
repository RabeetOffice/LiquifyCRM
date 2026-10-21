<?php

declare(strict_types=1);

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Livewire\App\FollowUps;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * A follow-up in the client's time (user request, 21 Oct 2026): "I will call
 * you at 5 PM UK time" is set as 17:00 London, the reminders come at that
 * moment wherever the owner is, and the follow-up says it both ways.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'timezone' => 'Europe/London']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->agent = agentOf($this->department, ['name' => 'Bill Graham', 'timezone' => 'Asia/Karachi']);

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(),
        'name' => 'Sarah Lee', 'phone' => '+44 7700 900123',
    ]);
});

it('sets a follow-up at a time in the client\'s time zone and says it both ways', function (): void {
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openFollowUp')
        ->assertSet('followUpZone', 'Asia/Karachi')
        ->assertSee('In whose time')
        ->assertSee('London')
        ->set('followUpAt', '2026-10-22T17:00')
        ->set('followUpZone', 'Europe/London')
        ->set('followUpTitle', 'Call about the cover')
        ->call('scheduleFollowUp')
        ->assertHasNoErrors()
        ->set('tab', 'tasks')
        ->assertSee('5:00 PM London time');

    $task = Task::query()->sole();

    // 17:00 in London on 22 October (summer time) is 16:00 UTC, 21:00 in Karachi.
    expect($task->due_at->toDateTimeString())->toBe('2026-10-22 16:00:00')
        ->and($task->timezone)->toBe('Europe/London')
        ->and($task->theirTime($this->agent))->toBe('5:00 PM London time');

    Livewire::actingAs($this->agent)->test(FollowUps::class, ['brand' => $this->brand])
        ->call('showList', 'upcoming')
        ->assertSee('5:00 PM London time');

    // The reminder comes ten minutes before that moment, and says the time as it was agreed.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 15:50:00', 'UTC'));
    $this->artisan('followups:remind')->assertSuccessful();

    Notification::assertSentTo($this->agent, AppNotification::class, fn (AppNotification $n): bool => $n->title === 'Follow-up in 10 minutes: Call about the cover'
        && str_contains($n->body, 'At 5:00 PM London time.'));
});

it('keeps no time zone for a follow-up in the person\'s own time', function (): void {
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openFollowUp')
        ->set('followUpAt', '2026-10-22T17:00')
        ->call('scheduleFollowUp')
        ->assertHasNoErrors();

    $task = Task::query()->sole();

    expect($task->due_at->toDateTimeString())->toBe('2026-10-22 12:00:00')
        ->and($task->timezone)->toBeNull()
        ->and($task->theirTime($this->agent))->toBeNull();
});

it('gives every time zone to a signed-in person for the searches', function (): void {
    $this->actingAs($this->agent)->getJson(route('time-zones'))
        ->assertOk()
        ->assertJsonFragment(['value' => 'Europe/London', 'label' => 'London']);

    auth()->logout();
    $this->getJson(route('time-zones'))->assertUnauthorized();
});
