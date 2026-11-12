<?php

declare(strict_types=1);

use App\Modules\Chat\Actions\ReplyToVisitor;
use App\Modules\Chat\Livewire\App\ChatWidgets;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The attention grabber (a card with a picture, a question and two answers) and the visitor's thumbs up or down on a chat
 * (user request, 12 Nov 2026).
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = admin();
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'UK site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['ukpublishinghouse.co.uk'],
    ]);
    inBrand($this->brand);
});

function rateHeaders(object $test): array
{
    $creds = $test->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key])->assertOk();

    return ['X-Chat-Key' => $test->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];
}

it('saves the attention grabber and sends it to the website', function (): void {
    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('edit', $this->widget->getKey())
        ->set('grabber', true)->set('grabberArt', 'book')->set('grabberText', 'Writing a book? Let us help.')
        ->set('grabberYes', 'Tell me more')->set('grabberNo', 'Not now')
        ->call('save')->assertHasNoErrors();

    $this->getJson('/chat/api/config?key='.$this->widget->widget_key, ['Origin' => 'https://ukpublishinghouse.co.uk'])->assertOk()
        ->assertJson(['grabber' => true, 'grabber_art' => '📚', 'grabber_text' => 'Writing a book? Let us help.', 'grabber_yes' => 'Tell me more', 'grabber_no' => 'Not now']);

    Livewire::actingAs($this->manager)
        ->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('edit', $this->widget->getKey())
        ->set('grabberArt', 'skull')->set('grabberYes', str_repeat('y', 25))
        ->call('save')->assertHasErrors(['grabberArt', 'grabberYes'])->assertSet('tab', 'behaviour');
});

it('lets a visitor rate a chat the team answered, change their mind, and tells the team', function (): void {
    $headers = rateHeaders($this);
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], $headers)->assertOk();

    // Nothing to rate before the team answers.
    $this->postJson('/chat/api/rate', ['rating' => 'up'], $headers)->assertStatus(422);

    $chat = Conversation::query()->sole();
    app(ReplyToVisitor::class)->reply($chat, $this->manager, 'Happy to help!');

    $this->postJson('/chat/api/rate', ['rating' => 'up'], $headers)->assertOk();
    expect($chat->refresh()->rating)->toBe('up')->and($chat->rated_at)->not->toBeNull();

    $this->postJson('/chat/api/rate', ['rating' => 'down'], $headers)->assertOk();
    $this->postJson('/chat/api/rate', ['rating' => 'meh'], $headers)->assertStatus(422);

    expect($chat->refresh()->rating)->toBe('down')
        ->and(Message::query()->where('kind', 'event')->pluck('body')->all())->toContain('The visitor rated this chat good 👍', 'The visitor rated this chat bad 👎');

    // The chat box hears its own rating back, and the team sees it beside the chat.
    $this->postJson('/chat/api/init', [], $headers)->assertJsonPath('rating', 'down');
    $this->actingAs($this->manager)->get('/b/ukph/live-chat/chats?chat='.$chat->getKey())->assertOk()->assertSee('Not good');

    // Rating events are for the team, never sent back to the visitor as messages.
    expect(collect($this->getJson('/chat/api/messages?after=0', $headers)->json('messages'))->pluck('body')->all())->not->toContain('The visitor rated this chat bad 👎');
});

it('refuses a rating when the brand switched rating off', function (): void {
    $this->widget->update(['appearance' => ['rating' => '0']]);
    $headers = rateHeaders($this);
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], $headers)->assertOk();
    app(ReplyToVisitor::class)->reply(Conversation::query()->sole(), $this->manager, 'Hi!');

    $this->postJson('/chat/api/rate', ['rating' => 'up'], $headers)->assertStatus(422);
    expect(Conversation::query()->sole()->rating)->toBeNull();
});
