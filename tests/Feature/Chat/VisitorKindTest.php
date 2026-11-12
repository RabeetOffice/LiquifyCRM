<?php

declare(strict_types=1);

use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\VisitorKind;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * Real person or probably a bot (user request, 12 Nov 2026): the chat button reports the first scroll, tap, click, key or
 * mouse movement; a VPN or data-centre browser that never does anything is probably a program.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->brand = brand(['name' => 'Australian Web Builders', 'slug' => 'awb']);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'AWB site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['australianwebbuilders.com.au'],
    ]);
    inBrand($this->brand);
});

function pingAs(object $test, string $vid, bool $human): void
{
    $test->call('POST', '/chat/api/ping', [], [], [], ['HTTP_ORIGIN' => 'https://australianwebbuilders.com.au', 'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Safari/604.1'],
        json_encode(['key' => $test->widget->widget_key, 'vid' => $vid, 'url' => 'https://australianwebbuilders.com.au/', 'human' => $human]))->assertOk();
}

it('notes the first sign of a person once, and calls that browser a real person', function (): void {
    $vid = (string) Str::uuid();
    pingAs($this, $vid, false);
    $session = ChatSession::query()->sole();
    expect($session->interacted_at)->toBeNull();

    pingAs($this, $vid, true);
    $first = $session->refresh()->interacted_at;
    expect($first)->not->toBeNull()->and(VisitorKind::of($session))->toBe('person');

    $this->travel(5)->minutes();
    pingAs($this, $vid, true);
    expect($session->refresh()->interacted_at->equalTo($first))->toBeTrue();
});

it('calls a quiet browser from a data centre probably a bot, and leaves a quiet ordinary browser undecided', function (): void {
    pingAs($this, (string) Str::uuid(), false);
    $session = ChatSession::query()->sole();
    $session->forceFill(['isp' => 'Google LLC', 'is_proxy' => true, 'country' => 'PL'])->save();

    // Just arrived: too soon to say.
    expect(VisitorKind::of($session))->toBeNull();

    $this->travel(30)->seconds();
    expect(VisitorKind::of($session->refresh()))->toBe('bot');

    $this->actingAs(admin())->get('/b/awb/live-chat/visitors')->assertOk()->assertSee('Probably a bot');

    // The same browser from a home connection: not decided, however quiet.
    $session->forceFill(['isp' => 'Cyber Internet Services', 'is_proxy' => false, 'country' => 'PK'])->save();
    expect(VisitorKind::of($session->refresh()))->toBeNull()
        // Writing in the chat is a person, whatever the address.
        ->and(VisitorKind::of($session, wrote: true))->toBe('person');
});
