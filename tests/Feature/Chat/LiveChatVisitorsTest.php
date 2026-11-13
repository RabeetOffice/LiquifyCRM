<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\LiveChats;
use App\Modules\Chat\Livewire\App\LiveVisitors;
use App\Modules\Chat\Models\ChatPageView;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Leads\Actions\DeleteLeadForever;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Live chat, fourth update (3 Nov 2026 plan): who is on the website now, the pages they read, an invitation to chat, and
 * the one "Live chat" tab of the sidebar that holds all of it.
 */

const VID_ONE = '3f2b8c1a-9d4e-4f6a-8b1c-2d3e4f5a6b7c';
const VID_TWO = '7a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();

    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department, ['name' => 'Farah Front']);
    $this->agent = agentOf($this->department, ['name' => 'Sami Seller']);
    $this->admin = admin();
    $this->widget = ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie']]);

    inBrand($this->brand);
});

/** The launcher on the website reports a page: a plain-text body holding JSON, from the website's own origin. */
function ping(object $test, array $body, string $origin = 'https://www.dublinpublishingpress.ie', array $server = [])
{
    return $test->call('POST', '/chat/api/ping', [], [], [], ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36', ...$server], json_encode(['key' => $test->widget->widget_key, 'vid' => VID_ONE, ...$body]));
}

it('notes a browser on the website with the page it is on, without anything after the question mark', function (): void {
    $response = ping($this, ['url' => 'https://www.dublinpublishingpress.ie/memoirs?email=roy@example.com&utm=x#top', 'title' => '<b>Memoir  publishing</b> | DPP', 'referrer' => 'https://www.google.com/search?q=memoir+publisher'], server: ['HTTP_CF_IPCOUNTRY' => 'IE']);

    $response->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://www.dublinpublishingpress.ie')
        ->assertJson(['invite' => null]);

    $session = ChatSession::query()->sole();
    expect($session->vid)->toBe(VID_ONE)
        ->and($session->current_url)->toBe('https://www.dublinpublishingpress.ie/memoirs')
        ->and($session->current_title)->toBe('Memoir publishing | DPP')
        ->and($session->referrer)->toBe('www.google.com')
        ->and($session->country)->toBe('IE')
        ->and($session->device)->toBe('computer')
        ->and($session->visits_count)->toBe(1)
        ->and($session->flag())->not->toBe('')
        ->and($session->path())->toBe('/memoirs');
    expect(ChatPageView::query()->sole()->url)->toBe('https://www.dublinpublishingpress.ie/memoirs');
    // Nothing of the address after the "?" is anywhere in the database.
    expect(json_encode(DB::table('chat_sessions')->get()).json_encode(DB::table('chat_page_views')->get()))->not->toContain('roy@example.com')->not->toContain('utm');
});

it('adds a line to the trail when the page changes, and counts a return after a pause as a new visit', function (): void {
    ping($this, ['url' => 'https://dublinpublishingpress.ie/', 'title' => 'Home'])->assertOk();
    ping($this, ['url' => 'https://dublinpublishingpress.ie/', 'title' => 'Home'])->assertOk();
    ping($this, ['url' => 'https://dublinpublishingpress.ie/pricing', 'title' => 'Pricing'])->assertOk();

    $session = ChatSession::query()->sole();
    expect(ChatPageView::query()->orderBy('id')->pluck('url')->all())->toBe(['https://dublinpublishingpress.ie/', 'https://dublinpublishingpress.ie/pricing'])
        ->and($session->current_title)->toBe('Pricing')
        ->and($session->visits_count)->toBe(1);

    DB::table('chat_sessions')->update(['last_seen_at' => now()->subMinutes(45)]);
    ping($this, ['url' => 'https://dublinpublishingpress.ie/pricing', 'title' => 'Pricing', 'referrer' => 'https://facebook.com/'])->assertOk();

    expect($session->fresh()->visits_count)->toBe(2)
        ->and($session->fresh()->referrer)->toBe('facebook.com')
        ->and(ChatPageView::query()->count())->toBe(3);
});

it('ignores a browser from another website, a switched-off chat, and a code that is not one', function (): void {
    // Another website: nothing is kept and the browser is not told it may read the answer.
    ping($this, ['url' => 'https://evil.example/'], 'https://evil.example')->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');
    ping($this, ['url' => 'https://dublinpublishingpress.ie/', 'vid' => 'not-a-uuid'])->assertNoContent();
    expect(ChatSession::query()->count())->toBe(0);

    // A page address that is not one of the widget's websites is not kept (the browser is, without a page).
    ping($this, ['url' => 'https://elsewhere.example/page'])->assertOk();
    expect(ChatSession::query()->sole()->current_url)->toBeNull()->and(ChatPageView::query()->count())->toBe(0);

    // Brand switched Live visitors off.
    ChatSession::query()->delete();
    $this->widget->update(['appearance' => ['track_visitors' => '0']]);
    ping($this, ['url' => 'https://dublinpublishingpress.ie/'])->assertNoContent();
    expect(ChatSession::query()->count())->toBe(0);
    $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertJsonPath('config.track', false);
    $this->getJson('/chat/api/config?key='.$this->widget->widget_key, ['Origin' => 'https://dublinpublishingpress.ie'])->assertOk()->assertJsonPath('track', false);
    $this->widget->update(['appearance' => ['track_visitors' => '1']]);
    $this->getJson('/chat/api/config?key='.$this->widget->widget_key, ['Origin' => 'https://dublinpublishingpress.ie'])->assertOk()->assertJsonPath('track', true);
});

it('shows the team who is on the website now, newest page first, and the pages one of them read', function (): void {
    ping($this, ['url' => 'https://dublinpublishingpress.ie/memoirs', 'title' => 'Memoir publishing'])->assertOk();
    ping($this, ['url' => 'https://dublinpublishingpress.ie/pricing', 'title' => 'Pricing'])->assertOk();
    ping($this, ['url' => 'https://dublinpublishingpress.ie/contact', 'title' => 'Contact', 'vid' => VID_TWO], server: ['HTTP_CF_IPCOUNTRY' => 'GB'])->assertOk();
    // A browser that left five minutes ago is not here.
    ChatSession::query()->where('vid', VID_TWO)->update(['last_seen_at' => now()->subMinutes(5)]);

    foreach ([$this->manager, $this->agent, $this->admin] as $person) {
        Livewire::actingAs($person)->test(LiveVisitors::class, ['brand' => $this->brand])
            ->assertSee('/pricing')
            ->assertDontSee('/contact')
            ->assertSee('Not chatting');
    }

    $session = ChatSession::query()->where('vid', VID_ONE)->sole();
    Livewire::actingAs($this->manager)->test(LiveVisitors::class, ['brand' => $this->brand])->call('toggle', $session->getKey())->assertSee('/memoirs')->assertSee('Memoir publishing');

    // The page is for people who see visitors: not when the brand says Managers only, and not for a stranger.
    $this->widget->update(['agents_see_visitors' => false]);
    Livewire::actingAs($this->agent)->test(LiveVisitors::class, ['brand' => $this->brand])->assertForbidden();
    $this->actingAs($this->agent)->get('/b/dpp/live-chat/visitors')->assertForbidden();
    $this->flushSession();
    $this->actingAs(managerOf(department(brand(['slug' => 'ukph']), ['name' => 'Sales'])))->get('/b/dpp/live-chat/visitors')->assertNotFound();
});

it('invites a visitor: the chat starts with the team\'s words, the browser is told once, and opening it takes over that chat', function (): void {
    ping($this, ['url' => 'https://dublinpublishingpress.ie/memoirs', 'title' => 'Memoir publishing'])->assertOk();
    $session = ChatSession::query()->sole();

    $screen = Livewire::actingAs($this->manager)->test(LiveVisitors::class, ['brand' => $this->brand]);
    $screen->call('invite', $session->getKey())->assertSet('showInvite', true)->assertSet('invitation', fn (string $text): bool => str_contains($text, 'Memoir publishing'));
    $screen->set('invitation', 'Hi! Can I help you find the right memoir package?')->call('sendInvitation')->assertHasNoErrors()->assertSet('showInvite', false);

    $chat = Conversation::query()->sole();
    $session->refresh();
    expect($chat->channel)->toBe('livechat')
        ->and($chat->lead_id)->toBeNull()
        ->and($chat->chat_assigned_to)->toBe($this->manager->getKey())
        ->and($chat->first_response_at)->not->toBeNull()
        ->and(Message::query()->where('kind', 'text')->sole()->body)->toBe('Hi! Can I help you find the right memoir package?')
        ->and(Message::query()->where('kind', 'event')->latest('id')->first()->body)->toBe('Farah Front invited this visitor from /memoirs')
        ->and($session->chat_visitor_id)->toBe($chat->chat_visitor_id)
        ->and($session->invite_from)->toBe('Farah')
        ->and(Lead::query()->count())->toBe(0);
    $this->assertDatabaseHas('activity_log', ['event' => 'chat.invited', 'subject_id' => $chat->getKey()]);

    // The browser's next ping hands it over, once.
    ping($this, ['url' => 'https://dublinpublishingpress.ie/memoirs'])->assertJsonPath('invite.text', 'Hi! Can I help you find the right memoir package?')->assertJsonPath('invite.from', 'Farah');
    ping($this, ['url' => 'https://dublinpublishingpress.ie/memoirs'])->assertJsonPath('invite', null);

    // Another invitation so soon is refused; so is an empty or long one, or one to a browser that left.
    $screen->call('invite', $session->getKey())->set('invitation', 'Again?')->call('sendInvitation')->assertHasErrors('invitation');
    $other = ChatSession::query()->create(['brand_id' => $this->brand->getKey(), 'vid' => VID_TWO, 'last_seen_at' => now(), 'visit_started_at' => now(), 'first_seen_at' => now(), 'chat_widget_id' => $this->widget->getKey()]);
    $screen->call('invite', $other->getKey())->set('invitation', '   ')->call('sendInvitation')->assertHasErrors('invitation');
    $screen->set('invitation', str_repeat('a', 301))->call('sendInvitation')->assertHasErrors('invitation');
    $other->update(['last_seen_at' => now()->subMinutes(10)]);
    $screen->call('invite', $other->getKey())->assertNotFound();

    // The visitor opens the chat: the box asks with its code and is given the invited visitor's token and the message.
    $init = $this->postJson('/chat/api/init', ['vid' => VID_ONE], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $invited = ChatVisitor::query()->findOrFail($chat->chat_visitor_id);
    expect($init->json('token'))->toBe($invited->visitor_token)
        ->and(array_column($init->json('messages'), 'body'))->toBe(['Hi! Can I help you find the right memoir package?'])
        ->and($init->json('messages.0.from'))->toBe('team');
    // No second visitor was made for that.
    expect(ChatVisitor::query()->count())->toBe(1);

    // A browser the team did not invite is untouched by all this.
    $plain = $this->postJson('/chat/api/init', ['vid' => VID_TWO], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    expect($plain->json('token'))->not->toBe($invited->visitor_token)->and($plain->json('messages'))->toBe([]);
});

it('ties a browser to its chat, and the team sees the pages that led to it', function (): void {
    ping($this, ['url' => 'https://dublinpublishingpress.ie/', 'title' => 'Home'])->assertOk();
    ping($this, ['url' => 'https://dublinpublishingpress.ie/memoirs', 'title' => 'Memoir publishing'])->assertOk();

    $init = $this->postJson('/chat/api/init', ['vid' => VID_ONE], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'Do you publish memoirs?'], $headers)->assertOk();

    expect(ChatSession::query()->sole()->chat_visitor_id)->toBe(ChatVisitor::query()->sole()->getKey());
    $chat = Conversation::query()->sole();

    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->set('filter', 'visitors')->call('open', $chat->getKey())
        ->assertSee('Pages they read')->assertSee('/memoirs')->assertSee('Memoir publishing')->assertSee('/');

    // The live list says they are chatting.
    Livewire::actingAs($this->manager)->test(LiveVisitors::class, ['brand' => $this->brand])->assertSee('Chatting');
});

it('puts everything of the website chat inside one Live chat tab', function (): void {
    ChatSession::query()->create(['brand_id' => $this->brand->getKey(), 'vid' => VID_ONE, 'last_seen_at' => now()]);

    $manager = $this->actingAs($this->manager)->get('/b/dpp/inbox')->assertOk();
    $manager->assertSee('lq-side-parent', false)->assertSee('Live visitors')->assertSee('Saved replies')->assertDontSee('Chat boxes');
    // The old places are gone: Saved replies is not a top-level item any more, and Settings has no Live chat entry.
    expect(substr_count($manager->getContent(), 'Saved replies'))->toBeLessThanOrEqual(2);

    // An Agent has the tab too, without the chat boxes' settings; with visitors hidden, only Saved replies is left in it.
    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/dpp/inbox')->assertOk()->assertSee('Live visitors')->assertSee('Saved replies')->assertDontSee('Chat boxes');
    $this->widget->update(['agents_see_visitors' => false]);
    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/dpp/inbox')->assertOk()->assertDontSee('Live visitors')->assertSee('Saved replies');
});

it('deletes old visits and the pages they read, and a deleted lead takes its browser with it', function (): void {
    ping($this, ['url' => 'https://dublinpublishingpress.ie/old', 'title' => 'Old'])->assertOk();
    DB::table('chat_sessions')->update(['last_seen_at' => now()->subDays(40)]);
    ping($this, ['url' => 'https://dublinpublishingpress.ie/new', 'title' => 'New', 'vid' => VID_TWO])->assertOk();

    Artisan::call('chat:prune');
    expect(ChatSession::query()->count())->toBe(1)->and(ChatSession::query()->sole()->vid)->toBe(VID_TWO)->and(ChatPageView::query()->sole()->url)->toBe('https://dublinpublishingpress.ie/new');

    // A visitor who became a lead: deleting the lead for good deletes the browser's record and its pages too.
    $init = $this->postJson('/chat/api/init', ['vid' => VID_TWO], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], $headers)->assertOk();
    $this->postJson('/chat/api/details', ['email' => 'roy@example.com'], $headers)->assertOk();

    $lead = Lead::query()->sole();
    $lead->delete();
    app(DeleteLeadForever::class)->handle(Lead::withTrashed()->findOrFail($lead->getKey()), $this->admin);

    expect(ChatSession::query()->count())->toBe(0)->and(ChatPageView::query()->count())->toBe(0)->and(ChatVisitor::query()->count())->toBe(0);
});

it('serves the launcher that pings, and tells the privacy page about it', function (): void {
    $script = $this->get('/chat/widget.js')->assertOk()->getContent();
    expect($script)->toContain("chatUrl('api/ping')")->toContain('text/plain')->toContain('lq_vid_');

    $this->get('/privacy')->assertOk()->assertSee('Who is on a business')->assertSee('30 days after the browser was last there');
});

it('opens the chat box for the team’s first message and shows the later ones as cards (user request, 13 Nov 2026)', function (): void {
    $script = $this->get('/chat/widget.js')->assertOk()->getContent();

    // The launcher: the first message of a visit opens the chat, the next ones are cards; an invitation goes the same way,
    // the chat box's own greeting stays a card.
    expect($script)->toContain('function teamWrote(data)')->toContain("storage('sessionStorage', poppedKey, '1')")
        ->toContain("if (data.type === 'message' && !isOpen) { teamWrote(")
        ->toContain('if (data && data.invite) { invited(data.invite); }')
        ->toContain('words.textContent = text');

    // The chat box tells the launcher the words of a message that came while it was closed, and what is still unread.
    $frame = $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://dublinpublishingpress.ie'))->assertOk()->getContent();
    expect($frame)->toContain("tell({ type: 'message', text: String(text).slice(0, 300)")->toContain("STORE + '_seen'");
});

it('shows a waiting website chat and a visitor who just arrived on whatever screen is open (user request, 13 Nov 2026)', function (): void {
    $waiting = fn ($user) => $this->actingAs($user)->getJson('/chats/waiting')->assertOk();
    $outsider = managerOf(department(brand(['name' => 'UK Publishing House', 'slug' => 'ukph']), ['name' => 'Sales']), ['name' => 'Olga Outsider']);

    // A visitor arrives on the website: the people who work this brand's visitors are told, nobody else.
    ping($this, ['url' => 'https://dublinpublishingpress.ie/pricing', 'title' => 'Pricing'])->assertOk();
    $session = ChatSession::query()->sole();

    foreach ([$this->manager, $this->agent, $this->admin] as $person) {
        $waiting($person)->assertJsonCount(1, 'visitors')->assertJsonPath('visitors.0.id', $session->getKey())
            ->assertJsonPath('visitors.0.page', '/pricing')->assertJsonPath('visitors.0.brand', 'Dublin Publishing Press')
            ->assertJsonPath('visitors.0.url', url('/b/dpp/live-chat/visitors'))->assertJsonCount(0, 'chats');
        auth()->logout();
    }
    $waiting($outsider)->assertJsonCount(0, 'visitors');
    auth()->logout();

    // The visitor writes: a card for everybody who may answer, with the words and the chat's address.
    $init = $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $creds = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'Do you print hardbacks?'], $creds)->assertOk();
    $chat = Conversation::query()->sole();

    foreach ([$this->manager, $this->agent, $this->admin] as $person) {
        $waiting($person)->assertJsonCount(1, 'chats')->assertJsonPath('chats.0.id', $chat->getKey())->assertJsonPath('chats.0.text', 'Do you print hardbacks?')
            ->assertJsonPath('chats.0.count', 1)->assertJsonPath('chats.0.url', url('/b/dpp/live-chat/chats?chat='.$chat->getKey()));
        auth()->logout();
    }
    $waiting($outsider)->assertJsonCount(0, 'chats');
    auth()->logout();

    // Given to the Agent, it waits for the Agent alone.
    $chat->forceFill(['chat_assigned_to' => $this->agent->getKey()])->save();
    $waiting($this->agent)->assertJsonCount(1, 'chats');
    auth()->logout();
    $waiting($this->manager)->assertJsonCount(0, 'chats');
    auth()->logout();

    // Read or answered (the count is back to none), closed, or written long ago: no card and no ringing.
    $chat->forceFill(['unread_count' => 0])->save();
    $waiting($this->agent)->assertJsonCount(0, 'chats');
    $chat->forceFill(['unread_count' => 2, 'chat_status' => 'closed'])->save();
    $waiting($this->agent)->assertJsonCount(0, 'chats');
    $chat->forceFill(['chat_status' => 'open', 'last_inbound_at' => now()->subMinutes(31)])->save();
    $waiting($this->agent)->assertJsonCount(0, 'chats');
    auth()->logout();

    // A visit that began a while ago is no longer "just arrived".
    $this->travel(2)->minutes();
    $waiting($this->manager)->assertJsonCount(0, 'visitors');
    auth()->logout();

    // Nobody signed in gets nothing, and the cards are in every screen's layout.
    $this->getJson('/chats/waiting')->assertUnauthorized();
    $this->actingAs($this->manager)->get('/b/dpp')->assertOk()->assertSee('x-data="chatDesk"', false)->assertSee('Open chat');

    // The four sounds the pages play are in place and small.
    foreach (['notification', 'chat-message', 'new-visitor', 'call-ring'] as $sound) {
        expect(public_path('sounds/'.$sound.'.mp3'))->toBeFile()->and(filesize(public_path('sounds/'.$sound.'.mp3')))->toBeLessThan(60000);
    }
});

it('keeps the website chats under Live chat and the Inbox for Messenger and Instagram', function (): void {
    $init = $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $this->postJson('/chat/api/messages', ['body' => 'Do you print hardbacks?'], ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')])->assertOk();
    $chat = Conversation::query()->sole();

    Livewire::actingAs($this->manager)->test(LiveChats::class, ['brand' => $this->brand])->assertSee('Do you print hardbacks?')->assertSee('Live chats');
    Livewire::actingAs($this->manager)->test(Inbox::class, ['brand' => $this->brand])->assertDontSee('Do you print hardbacks?')->assertDontSee('Every channel');

    // An older bell entry that points at the Inbox opens the chat where it belongs now.
    Livewire::actingAs($this->manager)->withQueryParams(['chat' => (string) $chat->getKey()])->test(Inbox::class, ['brand' => $this->brand])
        ->assertRedirect(route('brand.live-chat.chats', ['brand' => $this->brand->slug, 'chat' => $chat->getKey()]));

    // The tab's Chats item carries the unread count; the Inbox's count leaves the website out.
    $page = $this->actingAs($this->manager)->get('/b/dpp/live-chat/chats')->assertOk()->assertSee('Chats');
    expect($page->getContent())->toContain('data-badge-for="'.route('brand.live-chat.chats', ['brand' => 'dpp']).'"');
});
