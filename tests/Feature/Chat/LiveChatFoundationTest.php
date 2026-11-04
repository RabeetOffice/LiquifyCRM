<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\ChatWidgets;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\ChannelCapabilities;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Meta\Models\Conversation;
use Livewire\Livewire;

/*
 * Live chat, phase 1 (user request, 3 Nov 2026; docs/LIVECHAT_PLAN.md): the widget a brand sets up and the
 * Inbox's view of visitors. A visitor who has not given an email address or a phone number is not a lead.
 */

beforeEach(function (): void {
    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department, ['name' => 'Sami Seller']);
    $this->admin = admin();

    $this->other = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->otherManager = managerOf(department($this->other, ['name' => 'Sales']));

    inBrand($this->brand);
});

/** A website chat whose visitor has not given any details: no lead. */
function visitorChat(object $test, array $attributes = []): Conversation
{
    $brandId = $attributes['brand_id'] ?? $test->brand->getKey();
    $visitor = ChatVisitor::query()->create(['brand_id' => $brandId, 'visitor_token' => fake()->uuid(), 'first_seen_at' => now(), 'last_seen_at' => now()]);

    return Conversation::query()->create([
        'brand_id' => $brandId, 'channel' => Conversation::CHANNEL_LIVECHAT, 'contact_external_id' => $visitor->visitor_token,
        'chat_visitor_id' => $visitor->getKey(), 'last_inbound_at' => now(), 'last_message_at' => now(), 'last_message_preview' => 'Do you publish memoirs?', 'unread_count' => 1,
        ...$attributes,
    ]);
}

it('lets a Manager add a live chat with a key, the websites it runs on and the line for the website', function (): void {
    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->assertSee('No live chat yet')
        ->call('create')
        ->set('name', 'Dublin site')
        ->set('domains', "https://www.DublinPublishingPress.ie/contact\ndublinpublishingpress.ie, shop.dublinpublishingpress.ie")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Dublin site');

    $widget = ChatWidget::query()->sole();

    expect($widget->widget_key)->toMatch('/^wk_[A-Za-z0-9]{24}$/')
        ->and($widget->allowed_domains)->toBe(['dublinpublishingpress.ie', 'shop.dublinpublishingpress.ie'])
        ->and($widget->is_active)->toBeTrue()
        ->and($widget->look('title'))->toBe('Chat with us')
        ->and($widget->snippet())->toBe('<script src="'.url('/chat/widget.js').'" data-key="'.$widget->widget_key.'" async></script>');

    $this->actingAs($this->manager)->get('/b/dpp/live-chat')->assertOk()->assertSee($widget->widget_key);
    $this->assertDatabaseHas('activity_log', ['event' => 'chat.widget_created', 'subject_id' => $widget->getKey()]);
});

it('refuses something that is not a website address', function (): void {
    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('create')
        ->set('domains', 'not a website')
        ->call('save')
        ->assertHasErrors('domains');

    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('create')
        ->set('domains', '')
        ->call('save')
        ->assertHasErrors('domains');

    expect(ChatWidget::query()->count())->toBe(0);
});

it('keeps the live chat settings to Admins and the brand\'s Managers', function (): void {
    $this->actingAs($this->agent)->get('/b/dpp/live-chat')->assertForbidden();
    $this->flushSession();
    $this->actingAs($this->otherManager)->get('/b/dpp/live-chat')->assertNotFound();
    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/dpp/live-chat')->assertOk();
    $this->flushSession();
    $this->actingAs($this->admin)->get('/b/dpp/live-chat')->assertOk();

    Livewire::actingAs($this->agent)->test(ChatWidgets::class, ['brand' => $this->brand])->assertForbidden();

    // The menu says so too.
    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/dpp/inbox')->assertOk()->assertSee('Live chat');
    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/dpp/inbox')->assertOk()->assertDontSee('Live chat');
});

it('switches a live chat off and on, and removes it', function (): void {
    $widget = ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie']]);

    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('toggle', $widget->getKey());
    expect($widget->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('toggle', $widget->getKey());
    expect($widget->fresh()->is_active)->toBeTrue();

    // Somebody else's brand's widget cannot be reached by id.
    $foreign = ChatWidget::query()->create(['brand_id' => $this->other->getKey(), 'name' => 'UK site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['ukph.co.uk']]);
    Livewire::actingAs($this->manager)->test(ChatWidgets::class, ['brand' => $this->brand])->call('toggle', $foreign->getKey())->assertNotFound();

    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('remove', $widget->getKey());
    expect(ChatWidget::query()->count())->toBe(0);
});

it('only lets a widget run on the websites listed for it', function (): void {
    $widget = new ChatWidget(['allowed_domains' => ['dublinpublishingpress.ie']]);

    expect($widget->allowsOrigin('https://www.dublinpublishingpress.ie'))->toBeTrue()
        ->and($widget->allowsOrigin('https://dublinpublishingpress.ie'))->toBeTrue()
        ->and($widget->allowsOrigin('https://shop.dublinpublishingpress.ie'))->toBeTrue()
        ->and($widget->allowsOrigin('https://evil-dublinpublishingpress.ie'))->toBeFalse()
        ->and($widget->allowsOrigin('https://dublinpublishingpress.ie.evil.com'))->toBeFalse()
        ->and($widget->allowsOrigin('not an origin'))->toBeFalse()
        ->and((new ChatWidget(['allowed_domains' => []]))->allowsOrigin('https://dublinpublishingpress.ie'))->toBeFalse();
});

it('shows a visitor with no details under Visitors, and never as a lead', function (): void {
    ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie']]);
    $chat = visitorChat($this);

    expect($chat->lead_id)->toBeNull()
        ->and($chat->displayName())->toBe('Visitor '.$chat->visitor->getKey())
        ->and($chat->channelLabel())->toBe('Live chat')
        ->and($chat->canReply())->toBeTrue()
        ->and(Lead::query()->count())->toBe(0);

    foreach ([$this->admin, $this->manager, $this->agent] as $person) {
        Livewire::actingAs($person)
            ->test(Inbox::class, ['brand' => $this->brand])
            // Not in the working views...
            ->assertDontSee('Do you publish memoirs?')
            ->set('filter', 'visitors')
            // ... but in their own.
            ->assertSee('Do you publish memoirs?')
            ->assertSee('Visitor '.$chat->visitor->getKey())
            ->call('open', $chat->getKey())
            ->assertSee('Visitor '.$chat->visitor->getKey())
            ->assertSee('This visitor is not a lead');
    }

    expect(Lead::query()->count())->toBe(0);
});

it('hides visitors from Agents when the brand says so, and from other brands', function (): void {
    ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie'], 'agents_see_visitors' => false]);
    $chat = visitorChat($this);

    Livewire::actingAs($this->agent)->test(Inbox::class, ['brand' => $this->brand])->set('filter', 'visitors')->assertDontSee('Do you publish memoirs?');
    Livewire::actingAs($this->manager)->test(Inbox::class, ['brand' => $this->brand])->set('filter', 'visitors')->assertSee('Do you publish memoirs?');

    // Nor can an Agent open it by its number.
    expect($this->agent->can('view', $chat))->toBeFalse()
        ->and($this->manager->can('view', $chat))->toBeTrue()
        ->and($this->admin->can('view', $chat))->toBeTrue()
        ->and($this->otherManager->can('view', $chat))->toBeFalse();

    // A Manager of another brand sees none of this brand's visitors in their own Inbox either.
    inBrand($this->other);
    Livewire::actingAs($this->otherManager)->test(Inbox::class, ['brand' => $this->other])->set('filter', 'visitors')->assertDontSee('Do you publish memoirs?');
});

it('keeps the website chat\'s own abilities out of the Messenger and Instagram channels', function (): void {
    expect(ChannelCapabilities::has('livechat', ChannelCapabilities::FILES))->toBeTrue()
        ->and(ChannelCapabilities::has('livechat', ChannelCapabilities::REPLY_WINDOW))->toBeFalse()
        ->and(ChannelCapabilities::has('messenger', ChannelCapabilities::REPLY_WINDOW))->toBeTrue()
        ->and(ChannelCapabilities::has('instagram', ChannelCapabilities::VISITOR_CARD))->toBeFalse()
        ->and(ChannelCapabilities::has('unknown', ChannelCapabilities::FILES))->toBeFalse();
});
