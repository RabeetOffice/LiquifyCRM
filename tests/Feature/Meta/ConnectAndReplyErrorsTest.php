<?php

declare(strict_types=1);

use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Actions\ConnectFacebook;
use App\Modules\Meta\Enums\MessageStatus;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * 14 October, live: "Connect again" with dozens of Pages ran past the host's
 * time limit (503) because every Page cost two calls to Meta inside the
 * request; and a reply Meta refused said only Meta's words. Now the Pages
 * come in one call and are subscribed after the answer, and the usual
 * refusals are said plainly.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret']);

    $this->brand = brand(['slug' => 'ukph']);
    $this->connection = MetaConnection::query()->create(['fb_user_id' => '42', 'fb_user_name' => 'Rabeet', 'user_token' => 'long-token']);
});

it('lists every Page in one call and subscribes only the Pages that need it', function (): void {
    MetaSource::query()->create(['external_id' => '5001', 'name' => 'Page 1', 'access_token' => 'old', 'subscribed_fields' => MetaSource::FIELDS, 'subscribed_at' => now()->subDay()]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => collect(range(1, 30))->map(fn (int $i): array => ['id' => (string) (5000 + $i), 'name' => 'Page '.$i, 'access_token' => 'token-'.$i])->all()]),
        'graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
    ]);

    expect(app(ConnectFacebook::class)->syncPages($this->connection))->toBe(30);

    // One list call and 29 subscriptions: the Page already subscribed is left alone.
    Http::assertSentCount(30);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/5001/subscribed_apps'));

    expect(MetaSource::query()->whereNotNull('subscribed_at')->count())->toBe(30)
        ->and(MetaSource::query()->where('external_id', '5001')->value('access_token'))->toBe('token-1');

    // Refresh Pages subscribes every Page again.
    app(ConnectFacebook::class)->syncPages($this->connection, force: true);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/5001/subscribed_apps'));
});

it('still connects a token without Instagram, and keeps the Instagram links it had', function (): void {
    $page = MetaSource::query()->create(['external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'old', 'instagram_id' => '17841400111', 'instagram_username' => 'ukph.books']);

    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), 'instagram_business_account') => Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 400),
        str_contains($request->url(), '/me/accounts') => Http::response(['data' => [['id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token']]]),
        default => Http::response(['success' => true]),
    });

    expect(app(ConnectFacebook::class)->syncPages($this->connection))->toBe(1)
        ->and($page->refresh()->instagramHandle())->toBe('@ukph.books')
        ->and($page->access_token)->toBe('page-token');
});

it('says plainly when Meta has blocked the account from sending', function (): void {
    $page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token', 'instagram_id' => '17841400111', 'instagram_username' => 'ukph.books']);
    inBrand($this->brand);
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Rabeet Ahmed']);
    $conversation = Conversation::query()->create([
        'brand_id' => $this->brand->getKey(), 'lead_id' => $lead->getKey(), 'channel' => 'instagram', 'contact_external_id' => '5550001',
        'meta_source_id' => $page->getKey(), 'last_inbound_at' => now()->subMinute(), 'last_message_at' => now()->subMinute(),
    ]);

    Http::fake(['graph.facebook.com/*/111/messages' => Http::response(['error' => [
        'message' => 'Based on previous use of this feature, your account has been temporarily blocked from taking this action.',
        'code' => 368, 'error_subcode' => 1390008,
    ]], 400)]);

    Livewire::actingAs(admin())
        ->test(ConversationThread::class, ['conversationId' => $conversation->getKey()])
        ->set('reply', 'yes')
        ->call('send')
        ->assertSee('Meta has blocked this account from sending messages for a while')
        ->assertSee('replies from Meta Business Suite are blocked too');

    $message = Message::query()->sole();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->error)->toContain('temporarily blocked from taking this action');

    $this->assertDatabaseHas('activity_log', ['event' => 'messages.reply_failed']);
});
