<?php

declare(strict_types=1);

use App\Modules\Chat\Actions\ReplyToVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
 * "Typing…" stops as soon as the box is empty again (user report, 12 Nov 2026: the dots stayed 10 to 15 seconds after the
 * words were deleted), on both sides.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->brand = brand(['name' => 'Australian Web Builders', 'slug' => 'awb']);
    $this->manager = managerOf(department($this->brand, ['name' => 'Front Sales']));
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'AWB site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['australianwebbuilders.com.au'],
    ]);
    inBrand($this->brand);

    $creds = $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $this->headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];
    $this->postJson('/chat/api/messages', ['body' => 'Hello'], $this->headers)->assertOk();
    $this->chat = Conversation::query()->sole();
    app(ReplyToVisitor::class)->reply($this->chat, $this->manager, 'Hi!');
});

it('stops the visitor’s dots for the team as soon as they empty their box', function (): void {
    $this->postJson('/chat/api/typing', [], $this->headers)->assertOk();
    expect(ChatTyping::visitorIsTyping((int) $this->chat->getKey()))->toBeTrue();

    $this->postJson('/chat/api/typing', ['stop' => true], $this->headers)->assertOk();
    expect(ChatTyping::visitorIsTyping((int) $this->chat->getKey()))->toBeFalse();
});

it('stops the team’s dots for the visitor as soon as the box is empty', function (): void {
    $url = route('brand.conversations.typing', ['brand' => 'awb', 'conversation' => $this->chat->getKey()]);

    $this->actingAs($this->manager)->postJson($url)->assertOk();
    $this->getJson('/chat/api/messages?after=0', $this->headers)->assertJson(['agent_typing' => true]);

    $this->actingAs($this->manager)->postJson($url.'?stop=1')->assertOk();
    $this->getJson('/chat/api/messages?after=0', $this->headers)->assertJson(['agent_typing' => false]);
});
