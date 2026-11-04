<?php

declare(strict_types=1);

use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * A lead form and a Messenger chat of the same person (user report and
 * decision, 4 Nov 2026). Seen on live: most forms do not name the chat (2 in
 * 14), and the person writes on Messenger a few minutes after the form, so
 * two leads came in. Under exactly the same full name, on the same Page,
 * within an hour, the two are one lead. A close name (Rob Alexander, Robert
 * James Alexander) is never joined: the chat goes to whoever works the form,
 * and the two are offered for merging.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret', 'liquify.meta.verify_token' => 'verify-me']);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->agent = agentOf($this->front, ['name' => 'Aly Reed']);

    Carbon::setTestNow('2026-11-04 04:40:00');
});

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $payload */
function twinWebhook(array $payload): void
{
    $body = (string) json_encode($payload);

    test()->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body)->assertOk();
}

/**
 * A lead form sent the way most of them come on live: no link to the chat.
 *
 * @param  array<string, string>  $more
 */
function twinForm(string $leadgenId, string $name, string $email, array $more = [], string $pageId = '111'): void
{
    Http::fake([
        'graph.facebook.com/*/'.$leadgenId.'*' => Http::response(['id' => $leadgenId, 'created_time' => now()->toIso8601String(), 'form_id' => '555', 'field_data' => [
            ['name' => 'full_name', 'values' => [$name]],
            ['name' => 'email', 'values' => [$email]],
            ['name' => 'what_type_of_book_is_it?', 'values' => ['Poetry']],
            ...array_map(fn (string $key, string $value): array => ['name' => $key, 'values' => [$value]], array_keys($more), $more),
        ]]),
        'graph.facebook.com/*/555*' => Http::response(['name' => 'Free consultation']),
    ]);

    twinWebhook(['object' => 'page', 'entry' => [['id' => $pageId, 'time' => time(), 'changes' => [[
        'field' => 'leadgen',
        'value' => ['leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => '555', 'created_time' => time()],
    ]]]]]);
}

/** A Messenger message from a person whose Facebook profile has this name. */
function twinChat(string $psid, string $profileName, string $text, string $mid, string $pageId = '111'): void
{
    [$first, $last] = explode(' ', $profileName, 2) + [1 => ''];

    Http::fake(['graph.facebook.com/*/'.$psid.'*' => Http::response(['first_name' => $first, 'last_name' => $last])]);

    twinWebhook(['object' => 'page', 'entry' => [['id' => $pageId, 'time' => time(), 'messaging' => [[
        'sender' => ['id' => $psid],
        'recipient' => ['id' => $pageId],
        'timestamp' => now()->getTimestampMs(),
        'message' => ['mid' => $mid, 'text' => $text],
    ]]]]]);
}

it('puts the chat of somebody who filled in the form minutes ago, under the same name, on that lead', function (): void {
    twinForm('9101', 'Billy Freedom', 'don@example.com');

    Carbon::setTestNow(now()->addMinutes(4));
    twinChat('8801', 'Billy Freedom', 'Before we go any further, I want to be upfront.', 'mid.b1');

    inBrand($this->brand);
    $lead = Lead::query()->sole();

    expect($lead->source_type)->toBe(LeadSource::Facebook)
        ->and($lead->email)->toBe('don@example.com')
        ->and(Conversation::query()->sole()->lead_id)->toBe($lead->getKey())
        ->and(Message::query()->sole()->body)->toBe('Before we go any further, I want to be upfront.')
        ->and($lead->activities()->where('event', 'leads.chat_joined')->sole()->description)
        ->toBe('Wrote on Messenger under the same name as this lead form, 4 minutes apart: the same person');
});

it('puts the form of somebody who is chatting under the same name on the chat lead', function (): void {
    twinChat('8802', 'Billy Freedom', 'Hello, I have three books.', 'mid.b2');

    Carbon::setTestNow(now()->addMinutes(10));
    twinForm('9102', 'billy  freedom', 'don@example.com');

    inBrand($this->brand);
    $lead = Lead::query()->sole();

    expect($lead->source_type)->toBe(LeadSource::Messenger)
        ->and($lead->email)->toBe('don@example.com')
        ->and($lead->fields)->toMatchArray(['form' => 'Free consultation', 'leadgen_id' => '9102'])
        ->and($lead->activities()->where('event', 'leads.form_joined')->sole()->description)
        ->toBe('Filled in the lead form "Free consultation" on Facebook under the same name as this chat, 10 minutes apart: the same person');
});

it('keeps two leads when the same name comes more than an hour apart, and offers them for merging', function (): void {
    twinForm('9103', 'Billy Freedom', 'don@example.com');

    Carbon::setTestNow(now()->addMinutes(75));
    twinChat('8803', 'Billy Freedom', 'Hi', 'mid.b3');

    inBrand($this->brand);
    $chatLead = Lead::query()->where('source_type', LeadSource::Messenger->value)->sole();

    expect(Lead::query()->count())->toBe(2)
        ->and(array_column(LeadMatcher::twins($chatLead, admin()), 'why'))->toBe(['the same name']);
});

it('never joins on one word, on another Page, or against another email or chat', function (): void {
    $other = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '222', 'name' => 'Second Page', 'access_token' => 'page-token-2']);

    // One word is not a name to join on.
    twinForm('9104', 'Maddie', 'maddie@example.com');
    twinChat('8804', 'Maddie', 'Hi', 'mid.m1');

    // The same name on another Page of the brand is somebody else's chat.
    twinForm('9105', 'Sharon Hudson', 'sharon@example.com');
    twinChat('8805', 'Sharon Hudson', 'Hi', 'mid.s1', pageId: '222');

    // A form that names another person's chat.
    twinForm('9106', 'Neil Burton', 'neil@example.com', ['inbox_url' => 'https://business.facebook.com/latest/5550001112223334?nav_ref=thread_view_by_psid']);
    twinChat('8806', 'Neil Burton', 'Hi', 'mid.n1');

    inBrand($this->brand);

    expect(Lead::query()->count())->toBe(6)
        ->and(Lead::query()->where('source_type', LeadSource::Messenger->value)->where('source_id', $other->getKey())->count())->toBe(1);

    // A chat lead whose person gave another email is not the form's either.
    $chat = Lead::query()->where('name', 'Neil Burton')->where('source_type', LeadSource::Messenger->value)->sole();
    $chat->forceFill(['email' => 'someone.else@example.com'])->save();

    expect(LeadMatcher::sameNameOnPage($this->brand, $this->page->getKey(), LeadSource::Messenger, 'Neil Burton', now(), 'neil@example.com'))->toBeNull()
        ->and(LeadMatcher::sameNameOnPage($this->brand, $this->page->getKey(), LeadSource::Messenger, 'Neil Burton', now(), 'someone.else@example.com')?->getKey())->toBe($chat->getKey());
});

it('gives the chat under a close name to whoever works the form, who can merge the two', function (): void {
    twinForm('9107', 'Rob Alexander', 'rob@example.com');

    inBrand($this->brand);
    $form = Lead::query()->sole();
    $form->forceFill(['department_id' => $this->front->getKey()])->save();
    app(AssignLead::class)->handle($form, $this->agent, admin());

    Carbon::setTestNow(now()->addMinutes(3));
    twinChat('8807', 'Robert James Alexander', 'Hi, about my book', 'mid.r1');

    inBrand($this->brand);
    $chat = Lead::query()->where('source_type', LeadSource::Messenger->value)->sole();

    // Two leads: a close name is never joined by itself.
    expect(Lead::query()->count())->toBe(2)
        ->and($chat->assigned_to)->toBe($this->agent->getKey())
        ->and($chat->department_id)->toBe($this->front->getKey());

    $twins = LeadMatcher::twins($chat, $this->agent);

    expect($twins)->toHaveCount(1)
        ->and($twins[0]['lead']->getKey())->toBe($form->getKey())
        ->and($twins[0]['why'])->toBe('a similar name, 3 minutes apart on the same Facebook Page')
        ->and($this->agent->can('merge', $chat))->toBeTrue()
        ->and($this->agent->can('merge', $form->refresh()))->toBeTrue();
});

it('routes the chat under a close name as usual while the form waits for somebody', function (): void {
    twinForm('9108', 'Rob Alexander', 'rob@example.com');

    Carbon::setTestNow(now()->addMinutes(3));
    twinChat('8808', 'Robert Alexander', 'Hi', 'mid.r2');

    inBrand($this->brand);

    expect(Lead::query()->count())->toBe(2)
        ->and(Lead::query()->where('source_type', LeadSource::Messenger->value)->sole()->assigned_to)->toBeNull();
});

it('tells the same name from a close one', function (string $one, string $other, bool $same, bool $close): void {
    expect(LeadMatcher::sameName($one, $other))->toBe($same)
        ->and(LeadMatcher::closeName($one, $other))->toBe($close);
})->with([
    ['Billy Freedom', 'billy  FREEDOM', true, false],
    ['Rob Alexander', 'Robert James Alexander', false, true],
    ['Laura Barker', 'Miss Laura Louise Barker', false, true],
    ['Mr Neil Burton', 'Neil Burton', true, false],
    ['Maddie', 'Maddie', false, false],
    ['J Smith', 'John Smith', false, false],
    ['Sharon Hudson', 'Sharon Carter', false, false],
    ['Kirsty Addo', 'Leslie Addo', false, false],
]);
