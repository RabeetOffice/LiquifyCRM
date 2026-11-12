<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\LiveChats;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\AgentPresence;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * From a website visitor's chat the team makes the lead (user request, 12 Nov 2026): an Agent writes down the details the
 * visitor gave, or sends the chat box's details form into the chat. And the chat box keeps saying "online" while somebody
 * of the team has a page open.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->brand = brand(['name' => 'Australian Web Builders', 'slug' => 'awb']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->agent = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'AWB site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['australianwebbuilders.com.au'],
    ]);
    inBrand($this->brand);

    $creds = $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $this->headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'Hi, I need a website'], $this->headers)->assertOk();
    $this->chat = Conversation::query()->sole();
});

it('lets an Agent write down the visitor’s details, which makes the lead with the chat on it', function (): void {
    $screen = Livewire::actingAs($this->agent)->test(LiveChats::class, ['brand' => $this->brand])->set('chat', (string) $this->chat->getKey())
        ->assertSee('Add their details')->assertSee('Ask in the chat')
        ->call('openVisitorDetails')->assertSet('showVisitorDetails', true);

    // A name alone is not a lead.
    $screen->set('visitorName', 'Ali Khan')->call('saveVisitorDetails')->assertHasErrors('visitorEmail');
    expect(Lead::query()->count())->toBe(0);

    $screen->set('visitorEmail', 'ali.khan@example.com')->call('saveVisitorDetails')->assertHasNoErrors()->assertSet('showVisitorDetails', false);

    $lead = Lead::query()->sole();
    expect($lead->name)->toBe('Ali Khan')->and($this->chat->refresh()->lead_id)->toBe($lead->getKey());
});

it('sends the details form into the visitor’s chat, even after they said "Not now"', function (): void {
    Livewire::actingAs($this->agent)->test(LiveChats::class, ['brand' => $this->brand])->set('chat', (string) $this->chat->getKey())
        ->call('askVisitorDetails')->assertHasNoErrors();

    $asked = collect($this->getJson('/chat/api/messages?after=0', $this->headers)->assertOk()->json('messages'))->firstWhere('ask', 'details');

    expect($asked)->not->toBeNull()->and($asked['from'])->toBe('team')->and($asked['body'])->toContain('email address or phone number');
});

it('does not let somebody who may not answer the chat make its lead', function (): void {
    $upsell = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));

    Livewire::actingAs($upsell)->test(LiveChats::class, ['brand' => $this->brand])->set('chat', (string) $this->chat->getKey())
        ->call('askVisitorDetails')->assertStatus(404);
});

it('keeps the chat box online while somebody keeps a page open, with no gap at the minute', function (): void {
    $this->travelTo(now());
    AgentPresence::seen((int) $this->agent->getKey(), (int) $this->brand->getKey());
    expect(AgentPresence::online((int) $this->brand->getKey()))->toBeTrue();

    // The page asks every few seconds: past the minute it is still here.
    foreach (range(1, 8) as $step) {
        $this->travel(10)->seconds();
        AgentPresence::seen((int) $this->agent->getKey(), (int) $this->brand->getKey());
        Cache::forget('chat:online:'.$this->brand->getKey());
        expect(AgentPresence::online((int) $this->brand->getKey()))->toBeTrue();
    }

    // The chat box hears it when it asks for news, not only when it opens.
    $this->getJson('/chat/api/messages?after=0', $this->headers)->assertJson(['online' => true]);

    // Gone a minute: away.
    $this->travel(70)->seconds();
    Cache::forget('chat:online:'.$this->brand->getKey());
    expect(AgentPresence::online((int) $this->brand->getKey()))->toBeFalse();
});
