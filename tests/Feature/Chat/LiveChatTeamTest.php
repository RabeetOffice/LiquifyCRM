<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\LiveChats;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\AgentPresence;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Chat\Support\VisitorToken;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Live;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Live chat, phase 3 (3 Nov 2026 plan): the team works a website chat from the Inbox. It answers, leaves notes the
 * visitor never sees, closes and reopens, gives a chat to somebody, hears about new chats, and the chat box knows
 * whether anybody is here.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();

    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department, ['name' => 'Farah Front']);
    $this->agent = agentOf($this->department, ['name' => 'Sami Seller']);
    $this->colleague = agentOf($this->department, ['name' => 'Cara Colleague']);
    $this->admin = admin();
    $this->widget = ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie']]);

    inBrand($this->brand);
});

/** A visitor writes in the chat box; returns their headers and the conversation. */
function visitorWrites(object $test, string $text = 'Do you publish memoirs?'): array
{
    $init = $test->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $test->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')];
    $test->postJson('/chat/api/messages', ['body' => $text], $headers)->assertOk();

    return [$headers, Conversation::query()->latest('id')->firstOrFail()];
}

/** The open chat as a person of the team sees it in the Inbox. */
function thread(object $test, mixed $user, Conversation $chat)
{
    return Livewire::actingAs($user)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true]);
}

it('lets a Manager answer a visitor from the Inbox, and the visitor reads it', function (): void {
    [$headers, $chat] = visitorWrites($this);
    expect($chat->first_response_at)->toBeNull()->and($chat->chat_assigned_to)->toBeNull();

    thread($this, $this->manager, $chat)->set('reply', 'Yes, we publish memoirs. Tell me more.')->call('send')->assertHasNoErrors();

    $chat->refresh();
    $reply = Message::query()->where('kind', 'text')->where('direction', 'out')->sole();

    expect($reply->body)->toBe('Yes, we publish memoirs. Tell me more.')
        ->and($reply->sent_by)->toBe($this->manager->getKey())
        ->and($reply->sender_type)->toBe('agent')
        ->and($chat->first_response_at)->not->toBeNull()
        ->and($chat->unread_count)->toBe(0)
        // Nobody had the chat: the first to answer has it, and the chat says so.
        ->and($chat->chat_assigned_to)->toBe($this->manager->getKey())
        ->and(Message::query()->where('kind', 'event')->sole()->body)->toBe('Farah Front took this chat');

    $messages = $this->getJson('/chat/api/messages', $headers)->assertOk()->json('messages');
    expect(array_column($messages, 'body'))->toBe(['Do you publish memoirs?', 'Yes, we publish memoirs. Tell me more.'])
        ->and($messages[1]['from'])->toBe('team')
        ->and($messages[1]['name'])->toBe('Farah');
});

it('keeps a note for the team out of the visitor\'s chat and out of the chat\'s preview', function (): void {
    [$headers, $chat] = visitorWrites($this);

    thread($this, $this->manager, $chat)->set('mode', 'note')->set('reply', 'Looks like a serious author')->call('send')->assertHasNoErrors();

    $note = Message::query()->where('kind', 'note')->sole();
    expect($note->body)->toBe('Looks like a serious author')
        ->and($chat->fresh()->last_message_preview)->toBe('Do you publish memoirs?')
        ->and($chat->fresh()->first_response_at)->toBeNull();

    $messages = $this->getJson('/chat/api/messages', $headers)->json('messages');
    expect(array_column($messages, 'body'))->toBe(['Do you publish memoirs?']);

    // The team sees it, as a note.
    thread($this, $this->manager, $chat)->assertSee('Looks like a serious author');
});

it('gives an unassigned chat to the first Agent who answers, and keeps a colleague out of it', function (): void {
    [, $chat] = visitorWrites($this);

    thread($this, $this->agent, $chat)->set('reply', 'Hello, Sami here.')->call('send')->assertHasNoErrors();
    $chat->refresh();
    expect($chat->chat_assigned_to)->toBe($this->agent->getKey());

    // A colleague reads it but cannot answer; the Manager and an Admin can.
    expect($this->colleague->can('view', $chat))->toBeTrue()
        ->and($this->colleague->can('reply', $chat))->toBeFalse()
        ->and($this->manager->can('reply', $chat))->toBeTrue()
        ->and($this->admin->can('reply', $chat))->toBeTrue();
    thread($this, $this->colleague, $chat)->set('reply', 'Let me jump in')->call('send')->assertForbidden();

    expect(Message::query()->where('kind', 'text')->where('direction', 'out')->count())->toBe(1);
});

it('closes a chat, refuses answers to it, and opens it again when the visitor writes', function (): void {
    [$headers, $chat] = visitorWrites($this);

    thread($this, $this->manager, $chat)->call('close');
    $chat->refresh();
    expect($chat->isClosed())->toBeTrue()->and($chat->chat_closed_at)->not->toBeNull()->and($chat->unread_count)->toBe(0);
    $this->assertDatabaseHas('activity_log', ['event' => 'chat.closed', 'subject_id' => $chat->getKey()]);

    thread($this, $this->manager, $chat)->set('reply', 'Anyone there?')->call('send')->assertHasErrors('reply');
    expect(Message::query()->where('direction', 'out')->where('kind', 'text')->count())->toBe(0);

    // The chat box is told once, and the chat leaves the working views for Closed.
    $this->getJson('/chat/api/messages', $headers)->assertJsonPath('closed', true);
    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->assertDontSee('Do you publish memoirs?');
    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'closed')->assertSee('Do you publish memoirs?');

    // They write again: it is open, with a line that says why, and the team is told.
    Notification::fake();
    Cache::flush();
    $this->postJson('/chat/api/messages', ['body' => 'One more question'], $headers)->assertOk();
    $chat->refresh();
    expect($chat->isClosed())->toBeFalse()
        ->and(Message::query()->where('kind', 'event')->latest('id')->first()->body)->toBe('Reopened: the visitor wrote again')
        ->and($chat->unread_count)->toBe(1);
    Notification::assertSentTo($this->manager, AppNotification::class);

    // Or the team reopens it by hand.
    thread($this, $this->manager, $chat)->call('close')->call('reopen');
    expect($chat->fresh()->isClosed())->toBeFalse();
});

it('lets a Manager give a visitor\'s chat to somebody of the team, and tells them', function (): void {
    [, $chat] = visitorWrites($this);
    Notification::fake();

    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->call('open', $chat->getKey())->assertSee('Looked after by')->call('assignChat', (string) $this->agent->getKey());

    expect($chat->fresh()->chat_assigned_to)->toBe($this->agent->getKey())
        ->and(Message::query()->where('kind', 'event')->sole()->body)->toBe('Farah Front gave this chat to Sami Seller');
    Notification::assertSentTo($this->agent, AppNotification::class, fn (AppNotification $notification): bool => str_contains($notification->title, 'given to you'));

    // Back to everybody.
    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->call('open', $chat->getKey())->call('assignChat', '');
    expect($chat->fresh()->chat_assigned_to)->toBeNull();

    // An Agent cannot give chats away, and nobody can give one to a person outside the brand.
    Livewire::actingAs($this->agent)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->call('open', $chat->getKey())->call('assignChat', (string) $this->colleague->getKey())->assertForbidden();
    $stranger = agentOf(department(brand(['slug' => 'ukph']), ['name' => 'Sales']), ['name' => 'Outsider']);
    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->call('open', $chat->getKey())->call('assignChat', (string) $stranger->getKey())->assertForbidden();
    expect($chat->fresh()->chat_assigned_to)->toBeNull();
});

it('rings the bell for a new visitor chat once, for the people who may answer it', function (): void {
    visitorWrites($this, 'Hello there');

    foreach ([$this->manager, $this->agent, $this->colleague] as $person) {
        Notification::assertSentToTimes($person, AppNotification::class, 1);
    }
    Notification::assertNotSentTo($this->admin, AppNotification::class);

    // More lines inside two minutes ring nothing more.
    $chat = Conversation::query()->sole();
    $visitor = $chat->visitor;
    $creds = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $visitor->visitor_token, 'X-Chat-Sig' => VisitorToken::sign($visitor->visitor_token)];
    $this->postJson('/chat/api/messages', ['body' => 'Second line'], $creds)->assertOk();
    Notification::assertSentToTimes($this->manager, AppNotification::class, 1);

    // Later, a chat somebody has rings only for them.
    $chat->forceFill(['chat_assigned_to' => $this->agent->getKey()])->save();
    Cache::forget('chat:told:'.$chat->getKey());
    $this->postJson('/chat/api/messages', ['body' => 'Third line'], $creds)->assertOk();
    Notification::assertSentToTimes($this->agent, AppNotification::class, 2);
    Notification::assertSentToTimes($this->colleague, AppNotification::class, 1);
});

it('leaves out Agents who may not see visitors when the brand says so', function (): void {
    $this->widget->update(['agents_see_visitors' => false]);

    visitorWrites($this, 'Hello there');

    Notification::assertSentTo($this->manager, AppNotification::class);
    Notification::assertNotSentTo($this->agent, AppNotification::class);
});

it('knows somebody of the team is here while a page of the brand is open, and tells the chat box', function (): void {
    $init = fn () => $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();

    expect(AgentPresence::online($this->brand->getKey()))->toBeFalse();
    $init()->assertJsonPath('config.online', false)->assertJsonPath('config.offline', fn (string $text): bool => str_contains($text, 'away'));

    // A page of the brand asks the server whether anything changed (every few seconds): that is "here".
    Live::read(Live::token($this->agent->getKey(), $this->brand->getKey()));
    Cache::forget('chat:online:'.$this->brand->getKey());

    expect(AgentPresence::online($this->brand->getKey()))->toBeTrue();
    $init()->assertJsonPath('config.online', true);

    // Somebody who looks at another brand does not make this one online.
    Cache::flush();
    Live::read(Live::token($this->agent->getKey(), brand(['slug' => 'ukph'])->getKey()));
    expect(AgentPresence::online($this->brand->getKey()))->toBeFalse();
});

it('shows each side when the other is typing', function (): void {
    [$headers, $chat] = visitorWrites($this);

    // The visitor types: the team's open chat shows the dots.
    thread($this, $this->manager, $chat)->assertDontSeeHtml('The visitor is typing');
    $this->postJson('/chat/api/typing', [], $headers)->assertOk();
    expect(ChatTyping::visitorIsTyping($chat->getKey()))->toBeTrue();
    thread($this, $this->manager, $chat)->assertSeeHtml('The visitor is typing');

    // The team types: the visitor's next ask says so, until a message is sent.
    $this->getJson('/chat/api/messages', $headers)->assertJsonPath('agent_typing', false);
    $this->actingAs($this->manager)->postJson('/b/dpp/conversations/'.$chat->getKey().'/typing')->assertOk();
    $this->getJson('/chat/api/messages', $headers)->assertJsonPath('agent_typing', true);
    thread($this, $this->manager, $chat)->set('reply', 'On it')->call('send');
    $this->getJson('/chat/api/messages', $headers)->assertJsonPath('agent_typing', false);

    // Only somebody who may answer the chat is heard.
    $chat->forceFill(['chat_assigned_to' => $this->agent->getKey()])->save();
    $this->flushSession();
    $this->actingAs($this->colleague)->postJson('/b/dpp/conversations/'.$chat->getKey().'/typing')->assertForbidden();
});

it('treats a website chat that has a lead like any chat of a lead', function (): void {
    [$headers, $chat] = visitorWrites($this);
    $this->postJson('/chat/api/details', ['name' => 'Roy', 'email' => 'roy@example.com'], $headers)->assertOk()->assertJson(['lead' => true]);

    $lead = Lead::query()->sole();
    $chat->refresh();
    expect($chat->lead_id)->toBe($lead->getKey());

    // The lead is free: front sales takes it before answering, a colleague only reads.
    expect($this->agent->can('reply', $chat))->toBeFalse()->and($this->admin->can('reply', $chat))->toBeTrue();
    $lead->forceFill(['assigned_to' => $this->agent->getKey(), 'department_id' => $this->department->getKey()])->save();
    expect($this->agent->fresh()->can('reply', $chat->fresh()))->toBeTrue()
        ->and($this->colleague->fresh()->can('reply', $chat->fresh()))->toBeFalse();

    thread($this, $this->agent, $chat)->set('reply', 'Hello Roy')->call('send')->assertHasNoErrors();
    expect(Message::query()->where('kind', 'text')->where('direction', 'out')->sole()->body)->toBe('Hello Roy')
        // A chat with a lead is looked after through the lead: no second owner on the chat.
        ->and($chat->fresh()->chat_assigned_to)->toBeNull();
});
