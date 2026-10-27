<?php

declare(strict_types=1);

use App\Modules\Core\Livewire\BrandDashboard;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Actions\TakeLead;
use App\Modules\Leads\Exceptions\LeadAlreadyTaken;
use App\Modules\Leads\Livewire\App\FollowUps;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sales\Actions\RecordSale;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Front sales (user request, 19 Oct 2026): everybody on a front sales team
 * sees the team's leads as they arrive, and whoever is free takes one for
 * themselves. Managers take leads too. A colleague's lead is only looked at.
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
});

it('lets an Agent take a free lead of their team in one click, and opens its contact', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Dean Williams', 'email' => 'dean@example.com']);

    expect($this->ali->can('take', $lead))->toBeTrue()
        ->and($this->ali->can('update', $lead))->toBeFalse();

    Livewire::actingAs($this->ali)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->assertSee('Dean Williams')
        ->assertDontSee('dean@example.com')
        ->call('takeLead', $lead->getKey())
        ->assertDispatched('lead-updated')
        ->assertDispatched('toast', title: 'You took Dean Williams')
        ->assertSee('dean@example.com');

    $lead->refresh();

    expect($lead->assigned_to)->toBe($this->ali->getKey())
        ->and($this->ali->can('update', $lead))->toBeTrue()
        ->and($this->ali->can('take', $lead))->toBeFalse()
        ->and($lead->activities()->where('event', 'leads.assigned')->value('description'))->toBe('Taken by Ali Khan')
        ->and($lead->activities()->where('event', 'leads.contact_revealed')->count())->toBe(1);

    // Nobody is told about their own click.
    Notification::assertNotSentTo($this->ali, AppNotification::class);
});

it('gives a lead to whoever clicked first and tells the other who has it', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Dean Williams']);
    $stale = Lead::query()->findOrFail($lead->getKey());

    app(TakeLead::class)->handle($lead, $this->ali);

    // Sara's screen still showed it free: the lock refuses her, by name.
    expect(fn () => app(TakeLead::class)->handle($stale, $this->sara))->toThrow(LeadAlreadyTaken::class, 'Ali Khan took this lead a moment before you.');

    Livewire::actingAs($this->sara)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->call('takeLead', $lead->getKey())
        ->assertDispatched('toast', title: 'Already taken', body: 'Ali Khan is on this lead.');

    expect($lead->fresh()->assigned_to)->toBe($this->ali->getKey());
});

it('puts a lead taken by mistake back for the others with Undo', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey()]);

    $list = Livewire::actingAs($this->ali)->test(LeadsIndex::class, ['brand' => $this->brand])->call('takeLead', $lead->getKey());
    expect($lead->fresh()->assigned_to)->toBe($this->ali->getKey());

    $list->call('undoLeadChange', $lead->getKey(), 'take', null, 'token-1');

    expect($lead->fresh()->assigned_to)->toBeNull()
        ->and($this->sara->can('take', $lead->fresh()))->toBeTrue();
});

it('keeps a colleague\'s lead, other teams\' leads and Upsell out of reach of a take', function (): void {
    $sarasLead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->sara->getKey()]);
    $otherTeam = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => department($this->brand, ['name' => 'Publishing'])->getKey()]);
    $noTeam = Lead::factory()->create(['brand_id' => $this->brand->getKey()]);
    $upseller = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));

    expect($this->ali->can('view', $sarasLead))->toBeTrue()
        ->and($this->ali->can('take', $sarasLead))->toBeFalse()
        ->and($this->ali->can('take', $otherTeam))->toBeFalse()
        ->and($this->ali->can('take', $noTeam))->toBeTrue()
        ->and($upseller->can('take', $noTeam))->toBeFalse();

    Livewire::actingAs($this->ali)->test(LeadsIndex::class, ['brand' => $this->brand])
        ->call('takeLead', $sarasLead->getKey())
        ->assertDispatched('toast', title: 'Already taken');

    Livewire::actingAs($this->ali)->test(LeadsIndex::class, ['brand' => $this->brand])
        ->call('takeLead', $otherTeam->getKey())
        ->assertNotFound();

    // Taking a lead no team has yet puts it in the taker's team.
    app(TakeLead::class)->handle($noTeam, $this->ali);

    expect($sarasLead->fresh()->assigned_to)->toBe($this->sara->getKey())
        ->and($otherTeam->fresh()->assigned_to)->toBeNull()
        ->and($noTeam->fresh()->department_id)->toBe($this->front->getKey());
});

it('shows a colleague\'s lead read-only, without its contact or its sale', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->sara->getKey(), 'name' => 'Sarah Lee', 'email' => 'sarah@example.com']);
    app(RecordSale::class)->handle($lead, 4321, 'Publishing', now(), $this->sara);

    Livewire::actingAs($this->ali)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('Sarah Lee')
        ->assertSee('Sara Malik is on this lead')
        ->assertDontSee('sarah@example.com')
        ->assertDontSee('4,321')
        ->assertDontSee('Take this lead')
        ->assertDontSee('Add note')
        ->call('reveal')
        ->assertForbidden();

    // Its owner sees it all.
    Livewire::actingAs($this->sara)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('4,321');
});

it('lets a Manager take a lead, from the Take button or from the assign menu', function (): void {
    $first = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'First Lead']);
    $second = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Second Lead']);

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $first->getKey()])
        ->assertSee('Take this lead')
        ->call('takeLead', $first->getKey());

    Livewire::actingAs($this->manager)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->call('quickAssign', $second->getKey(), $this->manager->getKey());

    expect($first->fresh()->assigned_to)->toBe($this->manager->getKey())
        ->and($second->fresh()->assigned_to)->toBe($this->manager->getKey())
        ->and($second->activities()->where('event', 'leads.assigned')->value('description'))->toBe('Taken by Maria Manager')
        // Round robin still shares among Agents only; the assign menu offers the Manager too.
        ->and(array_column(app(AssignableAgents::class)->people($this->manager, $this->brand), 'name'))->toBe(['Maria Manager', 'Ali Khan', 'Sara Malik']);
});

it('alerts front sales and its Managers to a free lead with a bell (the email comes with the three-hourly summary)', function (): void {
    $lead = app(CreateLead::class)->handle($this->brand, ['name' => 'Fresh Lead', 'department_id' => $this->front->getKey()])->lead;
    $upseller = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));

    expect($lead->assigned_to)->toBeNull();

    $bellOnly = fn (AppNotification $notification, array $channels): bool => $notification->title === 'New lead: Fresh Lead' && $channels === ['database'];

    Notification::assertSentTo($this->ali, AppNotification::class, $bellOnly);
    Notification::assertSentTo($this->sara, AppNotification::class, $bellOnly);
    Notification::assertSentTo($this->manager, AppNotification::class, $bellOnly);
    Notification::assertSentToTimes($this->manager, AppNotification::class, 1);
    Notification::assertNotSentTo($upseller, AppNotification::class);
});

it('alerts every front sales team to a lead that has no team yet, and nobody to one round robin gives away', function (): void {
    $other = department($this->brand, ['name' => 'Second Front']);
    $omar = agentOf($other, ['name' => 'Omar Other']);
    $upseller = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));

    app(CreateLead::class)->handle($this->brand, ['name' => 'Loose Lead']);

    Notification::assertSentTo($this->ali, AppNotification::class, fn (AppNotification $notification): bool => $notification->title === 'New lead: Loose Lead');
    Notification::assertSentTo($omar, AppNotification::class, fn (AppNotification $notification): bool => $notification->title === 'New lead: Loose Lead');
    Notification::assertSentTo($this->manager, AppNotification::class, fn (AppNotification $notification): bool => $notification->title === 'New lead: Loose Lead');
    Notification::assertNotSentTo($upseller, AppNotification::class);

    $this->front->forceFill(['assignment_mode' => 'round_robin'])->save();
    app(CreateLead::class)->handle($this->brand, ['name' => 'Shared Lead', 'department_id' => $this->front->getKey()]);

    // Round robin gave it to Ali or Sara at once: nobody is asked to take it.
    $shared = Lead::query()->where('name', 'Shared Lead')->sole();
    $takeIt = fn (AppNotification $notification): bool => $notification->title === 'New lead: Shared Lead';

    expect($shared->assigned_to)->toBeIn([$this->ali->getKey(), $this->sara->getKey()]);
    Notification::assertNotSentTo($this->ali, AppNotification::class, $takeIt);
    Notification::assertNotSentTo($this->sara, AppNotification::class, $takeIt);
});

it('keeps an Agent\'s follow-ups and numbers their own while they see the team', function (): void {
    $saras = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->sara->getKey(), 'name' => 'Saras Lead']);
    app(ScheduleFollowUp::class)->handle($saras, now(), 'Call Sara\'s client', $this->sara);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Free One']);

    Livewire::actingAs($this->ali)
        ->test(FollowUps::class, ['brand' => $this->brand])
        ->assertDontSee('Call Sara');

    Livewire::actingAs($this->ali)
        ->test(BrandDashboard::class, ['brand' => $this->brand])
        ->assertSee('Free leads')
        ->assertSee('Free One')
        ->assertViewHas('myOpen', 0)
        ->assertViewHas('unassigned', 1);
});

it('lets front sales read a free chat and take it from the Inbox to answer it', function (): void {
    $page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token']);
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Amy Wong']);
    $chat = Conversation::query()->create([
        'brand_id' => $this->brand->getKey(), 'lead_id' => $lead->getKey(), 'contact_external_id' => '777', 'contact_name' => 'Amy Wong',
        'meta_source_id' => $page->getKey(), 'last_inbound_at' => now()->subHour(), 'last_message_at' => now()->subHour(), 'last_message_preview' => 'How much is publishing?', 'unread_count' => 1,
    ]);

    Livewire::actingAs($this->ali)
        ->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->assertSee('Nobody has this chat yet. Take it to reply.')
        ->assertDontSee('Write a reply');

    expect($chat->fresh()->unread_count)->toBe(1);

    Livewire::actingAs($this->ali)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->set('chat', (string) $chat->getKey())
        ->dispatch('inbox-take')
        ->assertDispatched('toast', title: 'You took Amy Wong');

    expect($lead->fresh()->assigned_to)->toBe($this->ali->getKey());

    Livewire::actingAs($this->ali)
        ->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->assertSee('Write a reply');

    expect($chat->fresh()->unread_count)->toBe(0);
});
