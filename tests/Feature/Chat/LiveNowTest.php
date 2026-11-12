<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\LiveChats;
use App\Modules\Chat\Livewire\App\LiveVisitors;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Who is on the website, where the team chats (user request, 12 Nov 2026): Live chat › Chats shows them above the chats,
 * a click opens their chat or starts one, Live visitors says "Chatting" only for a real chat and lands in the chat after
 * starting it, and crawlers and page testers are not visitors.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->brand = brand(['name' => 'Australian Web Builders', 'slug' => 'awb']);
    $this->agent = agentOf(department($this->brand, ['name' => 'Front Sales']));
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'AWB site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['australianwebbuilders.com.au'],
    ]);
    inBrand($this->brand);
});

function browse(object $test, string $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0'): string
{
    $vid = (string) Str::uuid();
    $test->call('POST', '/chat/api/ping', [], [], [], ['HTTP_ORIGIN' => 'https://australianwebbuilders.com.au', 'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => $agent],
        json_encode(['key' => $test->widget->widget_key, 'vid' => $vid, 'url' => 'https://australianwebbuilders.com.au/', 'title' => 'Home']));

    return $vid;
}

it('shows who is on the website above the chats, and starting a chat lands in it', function (): void {
    browse($this);
    $session = ChatSession::query()->sole();

    Livewire::actingAs($this->agent)->test(LiveChats::class, ['brand' => $this->brand])
        ->assertSee('On the website now · 1')->assertSee('live-chat/visitors?start='.$session->getKey(), false);

    $screen = Livewire::withQueryParams(['start' => (string) $session->getKey()])->actingAs($this->agent)->test(LiveVisitors::class, ['brand' => $this->brand])
        ->assertSet('showInvite', true)->assertSee('Not chatting')->assertSee('Start chat');

    $screen->set('invitation', 'Hi! Looking for a new website?')->call('sendInvitation')->assertHasNoErrors();
    $chat = Conversation::query()->sole();
    $screen->assertRedirect($this->brand->url('live-chat/chats').'?chat='.$chat->getKey());

    // Now there is a chat: the strip opens it, and Live visitors says so.
    Livewire::actingAs($this->agent)->test(LiveChats::class, ['brand' => $this->brand])->assertSee('wire:click="open('.$chat->getKey().')"', false);
    Livewire::actingAs($this->agent)->test(LiveVisitors::class, ['brand' => $this->brand])->assertSee('Chatting')->assertSee('Open chat');
});

it('does not count crawlers and page testers as visitors', function (): void {
    browse($this, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
    browse($this, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/120.0 Safari/537.36');
    browse($this, '');

    expect(ChatSession::query()->count())->toBe(0);

    browse($this);
    expect(ChatSession::query()->count())->toBe(1);
});

it('keeps one visitor for one browser, however often the chat box opens', function (): void {
    $vid = browse($this);

    // The chat box opens three times without the token it had (a page load in a browser that dropped it).
    foreach (range(1, 3) as $n) {
        $this->postJson('/chat/api/init', ['vid' => $vid], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    }

    expect(ChatVisitor::query()->count())->toBe(1);
});

it('shows the unread chats on the Live chat tab itself, and gives Managers and Agents no Chat boxes', function (): void {
    $creds = $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'Hello?'], $headers)->assertOk();
    $this->postJson('/chat/api/messages', ['body' => 'Anyone there?'], $headers)->assertOk();

    $page = $this->actingAs($this->agent)->get('/b/awb/live-chat/chats')->assertOk()->getContent();
    expect($page)->toMatch('~lq-side-parent-badge.*?<span class="lq-badge lq-badge-primary">2</span>~s')->not->toContain('Chat boxes');

    auth()->logout();
    $this->actingAs(managerOf(department($this->brand, ['name' => 'Sales 2'])))->get('/b/awb/live-chat/chats')->assertOk()->assertDontSee('Chat boxes');
    $this->get('/b/awb/live-chat')->assertForbidden();
});
