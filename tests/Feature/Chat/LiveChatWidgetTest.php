<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\LiveChats;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\VisitorToken;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Live chat, phase 2 (user request, 3 Nov 2026): what the chat box on a website does. A visitor opens it, writes,
 * and is a lead only when they give a valid email address or phone number.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department);
    $this->admin = admin();
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(),
        'allowed_domains' => ['dublinpublishingpress.ie'],
    ]);

    inBrand($this->brand);
});

/** A visitor opens the chat box: the token and signature it keeps. */
function openChat(object $test, ?array $creds = null, ?ChatWidget $widget = null): array
{
    $widget ??= $test->widget;
    $headers = ['X-Chat-Key' => $widget->widget_key, ...($creds !== null ? ['X-Chat-Token' => $creds['token'], 'X-Chat-Sig' => $creds['sig']] : [])];
    $response = $test->postJson('/chat/api/init', [], $headers)->assertOk();

    return ['token' => $response->json('token'), 'sig' => $response->json('sig'), 'response' => $response];
}

function chatHeaders(object $test, array $creds, ?ChatWidget $widget = null): array
{
    return ['X-Chat-Key' => ($widget ?? $test->widget)->widget_key, 'X-Chat-Token' => $creds['token'], 'X-Chat-Sig' => $creds['sig']];
}

it('answers the launcher only on the websites the widget is set up for', function (): void {
    $url = '/chat/api/config?key='.$this->widget->widget_key;

    $this->getJson($url, ['Origin' => 'https://www.dublinpublishingpress.ie'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://www.dublinpublishingpress.ie')
        ->assertJson(['active' => true, 'title' => 'Chat with us', 'position' => 'right']);

    $this->getJson($url, ['Origin' => 'https://evil.example'])->assertNotFound()->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->getJson('/chat/api/config?key=wk_'.str_repeat('a', 24), ['Origin' => 'https://dublinpublishingpress.ie'])->assertNotFound();
    $this->getJson('/chat/api/config?key=nonsense', ['Origin' => 'https://dublinpublishingpress.ie'])->assertNotFound();

    $this->widget->update(['is_active' => false]);
    $this->getJson($url, ['Origin' => 'https://dublinpublishingpress.ie'])->assertNotFound();
});

it('serves the launcher script and a chat box that only its websites may frame, with no cookie', function (): void {
    $this->get('/chat/widget.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->assertSee('data-key', false);
    // Kept by the visitor's browser for a few minutes, never by a website's page cache (it handed out an old launcher).
    expect((string) $this->get('/chat/widget.js')->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=300')->not->toContain('public');

    $frame = $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://www.dublinpublishingpress.ie'))->assertOk();
    expect($frame->headers->get('Content-Security-Policy'))->toBe('frame-ancestors https://dublinpublishingpress.ie https://*.dublinpublishingpress.ie')
        ->and($frame->headers->getCookies())->toBe([]);
    $frame->assertSee('Chat with us')->assertSee($this->widget->widget_key, false);

    $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://evil.example'))->assertNotFound();
    $this->get('/chat/frame/'.$this->widget->widget_key)->assertNotFound();
});

it('gives a new visitor a signed token, knows them again, and refuses a forged one', function (): void {
    $first = openChat($this);

    expect(VisitorToken::valid($first['token'], $first['sig']))->toBeTrue();
    $visitor = ChatVisitor::query()->sole();
    expect($visitor->visitor_token)->toBe($first['token'])->and($visitor->visits_count)->toBe(1)->and($visitor->device)->not->toBeNull();

    // The same browser a few minutes later is the same visitor, in the same visit.
    $again = openChat($this, $first);
    expect($again['token'])->toBe($first['token'])->and(ChatVisitor::query()->count())->toBe(1)->and($visitor->fresh()->visits_count)->toBe(1);

    // Half an hour later it is a new visit.
    $visitor->forceFill(['last_seen_at' => now()->subMinutes(40)])->save();
    openChat($this, $first);
    expect($visitor->fresh()->visits_count)->toBe(2);

    // A token with a signature that was not made here is not that visitor: a new one is made, the real one is untouched.
    $forged = openChat($this, ['token' => $first['token'], 'sig' => str_repeat('0', 40)]);
    expect($forged['token'])->not->toBe($first['token'])->and(ChatVisitor::query()->count())->toBe(2);

    $this->postJson('/chat/api/messages', ['body' => 'Hi'], chatHeaders($this, ['token' => $first['token'], 'sig' => str_repeat('0', 40)]))->assertUnauthorized();
    $this->postJson('/chat/api/messages', ['body' => 'Hi'], ['X-Chat-Key' => $this->widget->widget_key])->assertUnauthorized();
    $this->postJson('/chat/api/messages', ['body' => 'Hi'], chatHeaders($this, VisitorToken::issue()))->assertUnauthorized();
});

it('starts a chat with the first message, and a visitor who gave no details is not a lead', function (): void {
    $creds = openChat($this);

    $this->postJson('/chat/api/messages', ['body' => "Do you publish memoirs?\nI have 60,000 words."], chatHeaders($this, $creds))
        ->assertOk()
        ->assertJsonPath('message.from', 'visitor')
        ->assertJsonPath('suggest', null);

    $chat = Conversation::query()->sole();
    expect($chat->channel)->toBe('livechat')
        ->and($chat->lead_id)->toBeNull()
        ->and($chat->chat_widget_id)->toBe($this->widget->getKey())
        ->and($chat->unread_count)->toBe(1)
        ->and($chat->last_message_preview)->toBe('Do you publish memoirs? I have 60,000 words.')
        ->and(Message::query()->sole()->sender_type)->toBe('visitor')
        ->and(Lead::query()->count())->toBe(0);

    // It is in the Inbox under Visitors for the team, and the visitor reads it back.
    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->assertSee('Do you publish memoirs?');
    $this->getJson('/chat/api/messages', chatHeaders($this, $creds))->assertOk()->assertJsonCount(1, 'messages');

    // Somebody else's browser sees none of it.
    $other = openChat($this);
    $this->getJson('/chat/api/messages', chatHeaders($this, $other))->assertOk()->assertJsonCount(0, 'messages');
});

it('shows a visitor what the team wrote, never the internal notes', function (): void {
    $creds = openChat($this);
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], chatHeaders($this, $creds))->assertOk();
    $chat = Conversation::query()->sole();

    Message::query()->create(['conversation_id' => $chat->getKey(), 'direction' => 'out', 'kind' => 'text', 'sender_type' => 'agent', 'sent_by' => $this->manager->getKey(), 'body' => 'Hi, this is the team.']);
    Message::query()->create(['conversation_id' => $chat->getKey(), 'direction' => 'out', 'kind' => 'note', 'sender_type' => 'agent', 'sent_by' => $this->manager->getKey(), 'body' => 'Internal: looks like a hot lead']);

    $messages = $this->getJson('/chat/api/messages', chatHeaders($this, $creds))->assertOk()->json('messages');

    expect(array_column($messages, 'body'))->toBe(['Hello', 'Hi, this is the team.'])
        ->and($messages[1]['from'])->toBe('team')
        ->and($messages[1]['name'])->toBe(explode(' ', $this->manager->name)[0]);

    // Only what is new, when asked after a message.
    $this->getJson('/chat/api/messages?after='.$messages[0]['id'], chatHeaders($this, $creds))->assertJsonCount(1, 'messages');
});

it('keeps a name alone as no lead and refuses an email or phone that is not real', function (): void {
    $creds = openChat($this);
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], chatHeaders($this, $creds))->assertOk();

    $this->postJson('/chat/api/details', ['name' => 'Roy Bleicher'], chatHeaders($this, $creds))->assertOk()->assertJson(['ok' => true, 'lead' => false]);
    expect(Lead::query()->count())->toBe(0)->and(ChatVisitor::query()->sole()->name)->toBe('Roy Bleicher');

    $this->postJson('/chat/api/details', ['email' => 'not-an-email'], chatHeaders($this, $creds))->assertStatus(422)->assertJsonPath('errors.email', 'Please check your email address.');
    $this->postJson('/chat/api/details', ['email' => 'someone@mailinator.com'], chatHeaders($this, $creds))->assertStatus(422)->assertJsonPath('errors.email', 'Please check your email address.');
    $this->postJson('/chat/api/details', ['phone' => '12345'], chatHeaders($this, $creds))->assertStatus(422)->assertJsonPath('errors.phone', fn (string $message): bool => str_starts_with($message, 'Please check your phone number'));

    expect(Lead::query()->count())->toBe(0)->and(Conversation::query()->sole()->lead_id)->toBeNull();
});

it('makes the lead when a valid email is given, in the widget\'s brand, with the chat on it', function (): void {
    $creds = openChat($this);
    $this->postJson('/chat/api/messages', ['body' => 'Do you publish memoirs?'], chatHeaders($this, $creds))->assertOk();

    $this->postJson('/chat/api/details', ['name' => 'Roy Bleicher', 'email' => ' Roy@Example.com ', 'page' => 'https://dublinpublishingpress.ie/memoirs'], chatHeaders($this, $creds))
        ->assertOk()
        ->assertJson(['ok' => true, 'lead' => true]);

    $lead = Lead::query()->sole();
    $chat = Conversation::query()->sole();
    $visitor = ChatVisitor::query()->sole();

    expect($lead->brand_id)->toBe($this->brand->getKey())
        ->and($lead->source_type)->toBe(LeadSource::LiveChat)
        ->and($lead->name)->toBe('Roy Bleicher')
        ->and($lead->email)->toBe('roy@example.com')
        ->and($lead->message)->toBe('Do you publish memoirs?')
        ->and($lead->source_id)->toBe($this->widget->getKey())
        ->and($chat->lead_id)->toBe($lead->getKey())
        ->and($chat->contact_name)->toBe('Roy Bleicher')
        ->and($visitor->lead_id)->toBe($lead->getKey())
        ->and($visitor->email)->toBe('roy@example.com');

    // Encrypted at rest.
    expect($visitor->getRawOriginal('email'))->not->toContain('roy@example.com');
    $this->assertDatabaseHas('activity_log', ['event' => 'chat.lead_from_chat', 'subject_id' => $lead->getKey()]);

    // The chat left the Visitors view and is a chat of a lead now.
    Livewire::actingAs($this->admin)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->assertDontSee('Do you publish memoirs?');
    Livewire::actingAs($this->admin)->test(LiveChats::class, ['brand' => $this->brand])->assertSee('Do you publish memoirs?');
    $this->actingAs($this->admin)->get('/b/dpp/leads/'.$lead->getKey())->assertOk()->assertSee('Live chat')->assertSee('Open the live chat');

    // More details later change nothing about the lead's count.
    $this->postJson('/chat/api/details', ['phone' => '+353 1 234 5678'], chatHeaders($this, $creds))->assertOk();
    expect(Lead::query()->count())->toBe(1);
});

it('adds the chat to a lead the person already has, instead of making a second one', function (): void {
    $existing = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'name' => 'Roy B', 'email' => 'roy@example.com', 'phone' => null]);

    $creds = openChat($this);
    $this->postJson('/chat/api/messages', ['body' => 'Hello again'], chatHeaders($this, $creds))->assertOk();
    $this->postJson('/chat/api/details', ['name' => 'Roy', 'email' => 'roy@example.com'], chatHeaders($this, $creds))->assertOk()->assertJson(['lead' => true]);

    expect(Lead::query()->count())->toBe(1)
        ->and(Conversation::query()->sole()->lead_id)->toBe($existing->getKey())
        ->and(ChatVisitor::query()->sole()->lead_id)->toBe($existing->getKey());
    $this->assertDatabaseHas('activity_log', ['event' => 'leads.repeat_enquiry', 'subject_id' => $existing->getKey()]);
});

it('asks before using an email or number written in a message, and a book\'s ISBN is not a number', function (): void {
    $creds = openChat($this);

    $this->postJson('/chat/api/messages', ['body' => 'You can reach me on roy@example.com'], chatHeaders($this, $creds))
        ->assertOk()
        ->assertJsonPath('suggest.email', 'roy@example.com');
    expect(Lead::query()->count())->toBe(0);

    $this->postJson('/chat/api/messages', ['body' => 'My book is ISBN 978-1-4028-9462-6'], chatHeaders($this, $creds))->assertOk()->assertJsonPath('suggest', null);

    // Their yes is what makes the lead.
    $this->postJson('/chat/api/details', ['email' => 'roy@example.com'], chatHeaders($this, $creds))->assertOk()->assertJson(['lead' => true]);
    expect(Lead::query()->sole()->email)->toBe('roy@example.com');
});

it('takes the pre-chat form\'s question as the first message and makes the lead from it', function (): void {
    $creds = openChat($this);

    $this->postJson('/chat/api/details', ['name' => 'Anna Byrne', 'phone' => '+353 87 123 4567', 'message' => 'I need a cover designed.'], chatHeaders($this, $creds))
        ->assertOk()
        ->assertJson(['ok' => true, 'lead' => true])
        ->assertJsonPath('message.body', 'I need a cover designed.');

    $lead = Lead::query()->sole();
    expect($lead->message)->toBe('I need a cover designed.')
        ->and($lead->phone)->toBe('+353871234567')
        ->and(Conversation::query()->sole()->lead_id)->toBe($lead->getKey())
        ->and(Message::query()->sole()->body)->toBe('I need a cover designed.');
});

it('keeps nothing from a robot that fills the hidden field', function (): void {
    $creds = openChat($this);

    $this->postJson('/chat/api/details', ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam.example'], chatHeaders($this, $creds))->assertOk()->assertJson(['lead' => false]);

    expect(Lead::query()->count())->toBe(0)->and(ChatVisitor::query()->sole()->name)->toBeNull();
});

it('refuses empty, very long and link-heavy messages, and slows a flood down', function (): void {
    $creds = openChat($this);
    $headers = chatHeaders($this, $creds);

    $this->postJson('/chat/api/messages', ['body' => '   '], $headers)->assertStatus(422);
    $this->postJson('/chat/api/messages', ['body' => str_repeat('a', 2001)], $headers)->assertStatus(422)->assertJsonPath('error', 'That message is too long. Please send it in parts.');
    $this->postJson('/chat/api/messages', ['body' => 'see http://a.example http://b.example http://c.example'], $headers)->assertStatus(422)->assertJsonPath('error', 'Please send one link at a time.');
    expect(Message::query()->count())->toBe(0);

    for ($i = 0; $i < 17; $i++) {
        $this->postJson('/chat/api/messages', ['body' => 'message '.$i], $headers)->assertOk();
    }

    $this->postJson('/chat/api/messages', ['body' => 'one too many'], $headers)->assertStatus(429);
});

it('turns a banned visitor away', function (): void {
    $creds = openChat($this);
    ChatVisitor::query()->sole()->forceFill(['is_banned' => true])->save();

    $this->postJson('/chat/api/messages', ['body' => 'Hello'], chatHeaders($this, $creds))->assertForbidden();
    $this->postJson('/chat/api/init', [], chatHeaders($this, $creds))->assertForbidden();
    expect(Message::query()->count())->toBe(0);
});

it('does nothing for a widget that is off, or a token of another brand\'s widget', function (): void {
    $creds = openChat($this);
    $this->widget->update(['is_active' => false]);

    $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertNotFound();
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], chatHeaders($this, $creds))->assertNotFound();

    // A visitor of one brand's chat is not known to another brand's chat.
    $other = brand(['slug' => 'ukph']);
    $otherWidget = ChatWidget::query()->create(['brand_id' => $other->getKey(), 'name' => 'UK', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['ukph.co.uk']]);
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], chatHeaders($this, $creds, $otherWidget))->assertUnauthorized();
});
