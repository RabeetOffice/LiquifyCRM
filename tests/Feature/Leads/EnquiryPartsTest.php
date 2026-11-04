<?php

declare(strict_types=1);

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadEnquiry;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * What they asked for, by where it came from (user report, 4 Nov 2026):
 * after a lead form and a Messenger chat became one lead, the chat's message
 * and the form's answers looked the same. The form's answers are one part,
 * the chat's message another (shown as a chat), oldest first.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token']);
    $this->admin = admin();

    inBrand($this->brand);
});

/** A lead with a Messenger chat whose first message from the person is `$said`, sent at `$at`. */
function leadWithChat(Lead $lead, string $said, string $at): Conversation
{
    $chat = Conversation::query()->create(['brand_id' => $lead->brand_id, 'channel' => 'messenger', 'contact_external_id' => '8801', 'meta_source_id' => $lead->source_id, 'lead_id' => $lead->getKey(), 'contact_name' => $lead->name]);
    Message::query()->create(['conversation_id' => $chat->getKey(), 'direction' => Message::IN, 'body' => $said, 'status' => 'delivered', 'created_at' => $at]);

    return $chat;
}

it('shows the form and the Messenger message of a merged lead apart, the form first when it came first', function (): void {
    $said = 'Before we go any further, I want to be upfront about something.';

    $lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'source_type' => LeadSource::Facebook, 'source_id' => $this->page->getKey(),
        'name' => 'Billy Freedom', 'message' => $said, 'created_at' => '2026-11-04 04:43:00',
        'fields' => ['facebook_page' => 'UKPH Page', 'form' => 'Free consultation', 'tell_us_about_your_book' => 'Three New Books by J. Barboza III'],
    ]);
    leadWithChat($lead, $said, '2026-11-04 04:47:00');

    Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSeeInOrder(['Facebook lead form', 'Free consultation', 'Tell us about your book', 'Three New Books by J. Barboza III', 'Messenger', $said, 'Open the Messenger chat'])
        ->assertSeeHtml('<p class="lq-ask-bubble">'.e($said).'</p>');
});

it('puts the chat first when the person wrote before the form came', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'source_type' => LeadSource::Messenger, 'source_id' => $this->page->getKey(), 'message' => 'Hello, I have a book', 'fields' => ['facebook_page' => 'UKPH Page', 'form' => 'Free consultation', 'what_type_of_book_is_it' => 'poetry']]);

    $parts = LeadEnquiry::parts(
        $lead,
        ['channel' => 'Messenger', 'icon' => 'messenger', 'first' => 'Hello, I have a book', 'firstAt' => CarbonImmutable::parse('2026-11-04 04:40'), 'hasMessage' => true],
        CarbonImmutable::parse('2026-11-04 04:50'),
    );

    expect(array_column($parts, 'kind'))->toBe(['chat', 'form'])
        ->and($parts[0]['message'])->toBe('Hello, I have a book')
        ->and($parts[1]['title'])->toBe('Facebook lead form')
        ->and($parts[1]['detail'])->toBe('Free consultation')
        ->and($parts[1]['message'])->toBeNull()
        ->and($parts[1]['answers'])->toBe([['label' => 'What type of book is it?', 'value' => 'poetry']]);
});

it('names a single part by where it came from', function (): void {
    $website = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'source_type' => LeadSource::Website, 'message' => 'Call me tomorrow', 'fields' => ['website' => 'ukpublishinghouse.co.uk']]);
    $chat = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'source_type' => LeadSource::Messenger, 'message' => 'Hi there', 'fields' => ['facebook_page' => 'UKPH Page']]);
    $instagramForm = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'source_type' => LeadSource::Facebook, 'message' => null, 'fields' => ['platform' => 'ig', 'form' => 'Book offer', 'genre' => 'Memoir']]);

    expect(LeadEnquiry::parts($website))->sequence(
        fn ($part) => $part->toMatchArray(['kind' => 'form', 'title' => 'Website form', 'detail' => 'ukpublishinghouse.co.uk', 'message' => 'Call me tomorrow']),
    )
        ->and(LeadEnquiry::parts($chat))->sequence(
            fn ($part) => $part->toMatchArray(['kind' => 'chat', 'title' => 'Messenger', 'icon' => 'messenger', 'message' => 'Hi there']),
        )
        ->and(LeadEnquiry::parts($instagramForm))->sequence(
            fn ($part) => $part->toMatchArray(['kind' => 'form', 'title' => 'Instagram lead form', 'icon' => 'instagram', 'detail' => 'Book offer']),
        );
});

it('keeps a website message with the form even when the lead also has a chat', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'source_type' => LeadSource::Facebook, 'source_id' => $this->page->getKey(), 'message' => 'Typed in the form', 'fields' => ['form' => 'Free consultation']]);
    leadWithChat($lead, 'Hi, I filled in your form', now()->toDateTimeString());

    Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSeeInOrder(['Facebook lead form', 'Typed in the form', 'Messenger', 'Hi, I filled in your form']);
});
