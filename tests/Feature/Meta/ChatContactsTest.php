<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ChatContacts;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * The email and phone number a person writes in a chat (user request,
 * 4 Nov 2026: "capture the number and email from the chat and show the
 * merge"). They fill the chat lead's missing details, so the lead of the
 * same person's form is offered for merging by the same email or number.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret', 'liquify.meta.verify_token' => 'verify-me']);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP', 'website' => 'https://www.ukpublishinghouse.co.uk']);
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();
});

/** A message from a person whose Facebook profile has this name, signed as Meta signs it. */
function contactChat(string $psid, string $profileName, string $text, string $mid, bool $echo = false): void
{
    [$first, $last] = explode(' ', $profileName, 2) + [1 => ''];
    Http::fake(['graph.facebook.com/*/'.$psid.'*' => Http::response(['first_name' => $first, 'last_name' => $last])]);

    $body = (string) json_encode(['object' => 'page', 'entry' => [['id' => '111', 'time' => time(), 'messaging' => [[
        'sender' => ['id' => $echo ? '111' : $psid],
        'recipient' => ['id' => $echo ? $psid : '111'],
        'timestamp' => now()->getTimestampMs(),
        'message' => ['mid' => $mid, 'text' => $text, ...($echo ? ['is_echo' => true, 'app_id' => 999] : [])],
    ]]]]]);

    test()->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertOk();
}

it('finds the email and the real phone number a person wrote', function (string $text, ?string $email, ?string $phone): void {
    expect(ChatContacts::in($text, $this->brand))->toBe(['email' => $email, 'phone' => $phone]);
})->with([
    'both' => ['My email is Don.Secret@Gmail.com and my number 07792 331881.', 'don.secret@gmail.com', '+447792331881'],
    'international' => ['Call me on +44 7792 331881', null, '+447792331881'],
    'with brackets' => ['Number: (07792) 331-881, after 2pm', null, '+447792331881'],
    'a time after it' => ['07792 331881 2024 is when I finished', null, '+447792331881'],
    'an ISBN' => ['My book is ISBN 978-1-78645-012-3', null, null],
    'only numbers of a book' => ['It has 45000 words and 312 pages, priced 12.99', null, null],
    'the brand own email' => ['I already emailed info@ukpublishinghouse.co.uk', null, null],
    'nothing' => ['Hi, how much does publishing cost?', null, null],
]);

it('puts the email from the chat on the chat lead and offers the form lead with it for merging', function (): void {
    inBrand($this->brand);
    $form = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'source_type' => LeadSource::Facebook, 'source_id' => $this->page->getKey(), 'name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'phone' => '+447812345678']);

    contactChat('8901', 'Sara Leigh', 'Hi, I filled in your form. My email is Sarah@Example.com', 'mid.c1');

    inBrand($this->brand);
    $chat = Lead::query()->where('source_type', LeadSource::Messenger->value)->sole();
    $line = Activity::query()->where('event', 'leads.contact_from_chat')->sole();
    $twins = LeadMatcher::twins($chat, admin());

    expect($chat->email)->toBe('sarah@example.com')
        ->and($chat->phone)->toBeNull()
        ->and($line->description)->toBe('Email address taken from the chat')
        ->and($line->properties->toArray())->toBe(['taken' => ['email']])
        ->and(json_encode($line->properties))->not->toContain('sarah@')
        ->and($twins)->toHaveCount(1)
        ->and($twins[0]['lead']->getKey())->toBe($form->getKey())
        ->and($twins[0]['sure'])->toBeTrue()
        ->and($twins[0]['why'])->toBe('the same email');

    // A later message with the number fills the phone too, and the twin is the same person by both.
    contactChat('8901', 'Sara Leigh', 'and my mobile is 07812 345678', 'mid.c2');

    expect($chat->refresh()->phone)->toBe('+447812345678')
        ->and(Activity::query()->where('event', 'leads.contact_from_chat')->count())->toBe(2);
});

it('never replaces what the lead has, and never takes what the team wrote', function (): void {
    contactChat('8902', 'Neil Burton', 'Hello', 'mid.n1');

    inBrand($this->brand);
    $chat = Lead::query()->sole();
    $chat->forceFill(['email' => 'neil@example.com'])->save();

    // The team's own reply carries the office number: it is not the person's.
    contactChat('8902', 'Neil Burton', 'Call us on 07792 331881', 'mid.n2', echo: true);
    contactChat('8902', 'Neil Burton', 'My other email is neil.b@example.org', 'mid.n3');

    expect($chat->refresh()->email)->toBe('neil@example.com')
        ->and($chat->phone)->toBeNull()
        ->and(Activity::query()->where('event', 'leads.contact_from_chat')->exists())->toBeFalse();
});

it('reads the chats from before once a night', function (): void {
    contactChat('8903', 'Bianca Way', 'Hi', 'mid.w1');

    inBrand($this->brand);
    $chat = Lead::query()->sole();

    // A message stored before this came in: nothing was read from it then.
    Message::query()->create(['conversation_id' => Conversation::query()->sole()->getKey(), 'direction' => Message::IN, 'body' => 'You can reach me at bianca.way@example.com or 07400 123456', 'status' => 'delivered', 'created_at' => now()]);

    $this->artisan('leads:chat-contacts')->expectsOutputToContain('for 1 lead')->assertSuccessful();

    expect($chat->refresh()->email)->toBe('bianca.way@example.com')
        ->and($chat->phone)->toBe('+447400123456');

    $this->artisan('leads:chat-contacts')->expectsOutputToContain('for 0 leads')->assertSuccessful();
});
