<?php

declare(strict_types=1);

use App\Modules\Core\Navigation\Menu;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\TakeLead;
use App\Modules\Leads\Livewire\App\Performance\Conversion;
use App\Modules\Leads\Livewire\App\Performance\Leaderboard;
use App\Modules\Leads\Livewire\App\Performance\LostReasons;
use App\Modules\Leads\Livewire\App\Performance\ResponseSpeed;
use App\Modules\Leads\Livewire\App\Performance\TeamActivity;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadNote;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Models\Task;
use App\Modules\Leads\Support\TeamPerformance;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The Performance tab (user request, 27 Nov 2026): Leaderboard, Leads taken, Response speed, Activity,
 * Conversion and Lost reasons, per person and per month, for Admins and Managers.
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

    $this->status = fn (string $flag): LeadStatus => LeadStatus::query()->where($flag, true)->firstOrFail();
    $this->month = now()->format('Y-m');
    $this->ids = [(int) $this->ali->getKey(), (int) $this->sara->getKey()];
    $this->brandId = (int) $this->brand->getKey();
});

it('measures how fast each person picked up and first contacted their new leads', function (): void {
    $this->travelTo(now()->startOfMonth()->addDays(2)->setTime(10, 0));
    $quick = Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey(), 'status_id' => ($this->status)('is_default')->getKey()]);
    $slow = Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey(), 'status_id' => ($this->status)('is_default')->getKey()]);

    $this->travel(5)->minutes();
    app(TakeLead::class)->handle($quick, $this->ali);
    app(TakeLead::class)->handle($slow, $this->ali);
    $this->travel(5)->minutes();
    $contacted = LeadStatus::query()->where('is_default', false)->where('is_won', false)->where('is_lost', false)->ordered()->firstOrFail();
    app(ChangeLeadStatus::class)->handle($quick->fresh(), $contacted, $this->ali);

    $speed = TeamPerformance::speed($this->brandId, $this->ids, $this->month);

    expect($speed[$this->ali->getKey()])->toMatchArray(['leads' => 2, 'to_owner' => 5, 'to_contact' => 10, 'quick' => 1, 'waiting' => 1])
        ->and($speed[$this->sara->getKey()]['leads'])->toBe(0);

    Livewire::actingAs($this->manager)->test(ResponseSpeed::class, ['brand' => $this->brand])
        ->assertOk()->assertSee('Ali Khan')->assertSee('10 min');
});

it('counts what each person did in the month', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey()]);
    app(TakeLead::class)->handle($lead, $this->ali);

    LeadNote::query()->create(['brand_id' => $this->brandId, 'lead_id' => $lead->getKey(), 'user_id' => $this->ali->getKey(), 'body' => 'Called, no answer']);
    LeadNote::query()->create(['brand_id' => $this->brandId, 'lead_id' => $lead->getKey(), 'user_id' => $this->ali->getKey(), 'body' => 'Sent the quote']);
    Task::query()->create(['brand_id' => $this->brandId, 'lead_id' => $lead->getKey(), 'user_id' => $this->ali->getKey(), 'title' => 'Call back', 'due_at' => now()->subMinutes(30), 'done_at' => now()->subMinutes(10)]);
    Task::query()->create(['brand_id' => $this->brandId, 'lead_id' => $lead->getKey(), 'user_id' => $this->ali->getKey(), 'title' => 'Missed one', 'due_at' => now()->subMinute()]);

    $chat = Conversation::query()->create(['brand_id' => $this->brandId, 'lead_id' => $lead->getKey(), 'contact_external_id' => '42', 'last_message_at' => now()]);
    Message::query()->create(['conversation_id' => $chat->getKey(), 'direction' => Message::OUT, 'kind' => 'text', 'body' => 'Hello!', 'sent_by' => $this->ali->getKey()]);
    // A team note in the chat is not a reply.
    Message::query()->create(['conversation_id' => $chat->getKey(), 'direction' => Message::OUT, 'kind' => 'note', 'body' => 'Looks keen', 'sent_by' => $this->ali->getKey()]);

    $contacted = LeadStatus::query()->where('is_default', false)->where('is_won', false)->where('is_lost', false)->ordered()->firstOrFail();
    app(ChangeLeadStatus::class)->handle($lead->fresh(), $contacted, $this->ali);

    $activity = TeamPerformance::activity($this->brandId, $this->ids, $this->month);

    expect($activity[$this->ali->getKey()])->toBe(['notes' => 2, 'done' => 1, 'overdue' => 1, 'replies' => 1, 'moves' => 1, 'taken' => 1])
        ->and($activity[$this->sara->getKey()])->toBe(['notes' => 0, 'done' => 0, 'overdue' => 0, 'replies' => 0, 'moves' => 0, 'taken' => 0]);

    Livewire::actingAs($this->admin)->test(TeamActivity::class, ['brand' => $this->brand])->assertOk()->assertSee('Notes written');
});

it('works out each person\'s conversion and why their leads were lost', function (): void {
    $won = Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey(), 'status_id' => ($this->status)('is_won')->getKey(), 'assigned_to' => $this->ali->getKey()]);
    Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey(), 'status_id' => ($this->status)('is_lost')->getKey(), 'assigned_to' => $this->ali->getKey(), 'lost_reason' => 'price', 'last_activity_at' => now()]);
    Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey(), 'status_id' => ($this->status)('is_lost')->getKey(), 'assigned_to' => $this->ali->getKey(), 'last_activity_at' => now()]);
    Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey(), 'status_id' => ($this->status)('is_default')->getKey(), 'assigned_to' => $this->ali->getKey()]);

    expect(TeamPerformance::conversion($this->brandId, $this->ids, $this->month)[$this->ali->getKey()])->toBe(['leads' => 4, 'won' => 1, 'lost' => 2, 'open' => 1, 'rate' => 25]);

    $lost = TeamPerformance::lostReasons($this->brandId, $this->ids, $this->month)[$this->ali->getKey()];
    expect($lost['price'])->toBe(1)->and($lost['not_given'])->toBe(1)->and(array_sum($lost))->toBe(2);

    // Last month has none of it.
    expect(TeamPerformance::conversion($this->brandId, $this->ids, now()->subMonthNoOverflow()->format('Y-m'))[$this->ali->getKey()]['leads'])->toBe(0);

    Livewire::actingAs($this->manager)->test(Conversion::class, ['brand' => $this->brand])->assertOk()->assertSee('25%');
    Livewire::actingAs($this->manager)->test(LostReasons::class, ['brand' => $this->brand])->assertOk()->assertSee('Too expensive')->assertViewHas('rows', fn ($rows): bool => $rows->first()['name'] === 'Ali Khan');
    expect($won->exists)->toBeTrue();
});

it('opens the Leaderboard and picks only real months', function (): void {
    Livewire::actingAs($this->admin)->test(Leaderboard::class, ['brand' => $this->brand])
        ->assertOk()
        ->set('month', '2099-01')
        ->assertViewHas('shown', now()->format('Y-m'))
        ->set('month', now()->subMonthNoOverflow()->format('Y-m'))
        ->assertViewHas('shown', now()->subMonthNoOverflow()->format('Y-m'));
});

it('keeps every Performance screen to Admins and Managers, all in one menu tab', function (): void {
    $routes = ['brand.performance.leaderboard', 'brand.leads.taken', 'brand.performance.speed', 'brand.performance.activity', 'brand.performance.conversion', 'brand.performance.lost'];

    foreach ($routes as $route) {
        $this->actingAs($this->ali)->get(route($route, ['brand' => $this->brand->slug]))->assertForbidden();
        $this->actingAs($this->manager)->get(route($route, ['brand' => $this->brand->slug]))->assertOk();
    }

    $tab = collect(Menu::for($this->admin, $this->brand, 'brand.performance.speed'))->flatMap(fn (array $group): array => $group['items'])->firstWhere('label', 'Performance');

    expect(array_column($tab['children'], 'label'))->toBe(['Leaderboard', 'Leads taken', 'Response speed', 'Activity', 'Conversion', 'Lost reasons'])
        ->and($tab['active'])->toBeTrue();
});

it('shows a Manager only the people of their own teams', function (): void {
    $other = department($this->brand, ['name' => 'Other Sales']);
    agentOf($other, ['name' => 'Omar Other']);

    Livewire::actingAs($this->manager)->test(TeamActivity::class, ['brand' => $this->brand])->assertSee('Ali Khan')->assertDontSee('Omar Other');
    Livewire::actingAs($this->admin)->test(TeamActivity::class, ['brand' => $this->brand])->assertSee('Omar Other');

    // AssignLead is still how a Manager gives leads; it never counts as somebody's take.
    $lead = Lead::factory()->create(['brand_id' => $this->brandId, 'department_id' => $this->front->getKey()]);
    app(AssignLead::class)->handle($lead, $this->sara, $this->manager);
    expect(TeamPerformance::activity($this->brandId, $this->ids, $this->month)[$this->sara->getKey()]['taken'])->toBe(0);
});
