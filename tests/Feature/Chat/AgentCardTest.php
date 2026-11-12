<?php

declare(strict_types=1);

use App\Modules\Auth\Livewire\Profile;
use App\Modules\Chat\Actions\ReplyToVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The chat box's header shows the person looking after the chat: their live chat name, title and photo, kept on Profile
 * (user request, 12 Nov 2026).
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->brand = brand(['name' => 'Australian Web Builders', 'slug' => 'awb']);
    $this->agent = agentOf(department($this->brand, ['name' => 'Front Sales']), ['name' => 'Rabeet Ahmed', 'designation' => 'Developer']);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'AWB site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['australianwebbuilders.com.au'],
    ]);
    inBrand($this->brand);

    $creds = $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $this->headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], $this->headers)->assertOk();
});

it('lets a team member set the name, title and photo the website chat shows', function (): void {
    Livewire::actingAs($this->agent)->test(Profile::class)
        ->assertSee('In the website chat')
        ->set('chatName', 'Rabeet')->set('chatTitle', 'Publishing consultant')->call('saveChatProfile')->assertHasNoErrors()
        ->set('chatPhoto', UploadedFile::fake()->image('me.png', 400, 300))->assertHasNoErrors();

    $user = $this->agent->refresh();
    expect($user->chat_name)->toBe('Rabeet')->and($user->chat_title)->toBe('Publishing consultant')
        ->and($user->chat_photo)->toStartWith('data:image/')
        ->and(strlen((string) $user->chat_photo))->toBeLessThan(40000)
        ->and($user->toArray())->not->toHaveKey('chat_photo');
});

it('shows the person looking after the chat in the chat box, the photo only when it does not have it yet', function (): void {
    $this->agent->forceFill(['chat_name' => 'Rabeet', 'chat_title' => 'Publishing consultant'])->save();

    // Nobody has the chat yet: the chat box keeps its own title.
    $this->postJson('/chat/api/init', [], $this->headers)->assertJsonPath('agent', null);

    app(ReplyToVisitor::class)->reply(Conversation::query()->sole(), $this->agent, 'Hi! How can I help?');

    $this->postJson('/chat/api/init', [], $this->headers)
        ->assertJsonPath('agent.name', 'Rabeet')->assertJsonPath('agent.title', 'Publishing consultant');

    $this->getJson('/chat/api/messages?after=0&agent='.$this->agent->getKey(), $this->headers)
        ->assertJsonPath('agent.id', $this->agent->getKey())->assertJsonPath('agent.photo', null)
        ->assertJsonPath('messages.1.name', 'Rabeet');
});

it('falls back to the first name and the designation', function (): void {
    app(ReplyToVisitor::class)->reply(Conversation::query()->sole(), $this->agent, 'Hi!');

    $this->postJson('/chat/api/init', [], $this->headers)->assertJsonPath('agent.name', 'Rabeet')->assertJsonPath('agent.title', 'Developer');
});
