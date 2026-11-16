<?php

declare(strict_types=1);

use App\Modules\Core\Livewire\BrandDashboard;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadNote;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\LeadInsights;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * What the assistant works out about a lead (user request, 16 Nov 2026): a hot, warm or cold tag on a new enquiry,
 * why a lost lead was lost (with the numbers on the dashboard), a follow-up offered when a note names a day, and a
 * summary kept as a note. A person decides everything that is saved about what to do; contacts never leave.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department, ['name' => 'Vimal Agent']);
    $this->colleague = agentOf($this->department, ['name' => 'Cara Colleague']);

    inBrand($this->brand);
    config(['liquify.ai.groq_key' => 'gsk_test_key', 'liquify.ai.groq_models' => 'test-model']);
});

/** Groq answering with these words (the stand-in registered first keeps answering, so it reads the words wanted now). */
function assistantSays(string $words): void
{
    if (! app()->bound('test.assistant.faked')) {
        Http::fake(['api.groq.com/*' => fn () => Http::response(['choices' => [['message' => ['content' => app('test.assistant.words')]]]])]);
        app()->instance('test.assistant.faked', true);
    }

    app()->instance('test.assistant.words', $words);
}

/** Everything that went to Groq in the last request, as one text. */
function assistantHeard(): string
{
    $sent = '';
    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return true;
    });

    return (string) $sent;
}

function ownLead(object $test, array $attributes = []): Lead
{
    return Lead::factory()->create([
        'brand_id' => $test->brand->getKey(), 'department_id' => $test->department->getKey(), 'assigned_to' => $test->agent->getKey(),
        'name' => 'Sara Willsdon', 'email' => 'sara@willsdon.example', 'phone' => '+447700900123', ...$attributes,
    ]);
}

it('tags a new enquiry hot, warm or cold with a reason, and never a bulk import', function (): void {
    assistantSays('HOT | Manuscript finished and asks for a price today');

    $lead = app(CreateLead::class)->handle($this->brand, [
        'name' => 'Sara Willsdon', 'email' => 'sara@willsdon.example', 'phone' => '+447700900123', 'department_id' => $this->department->getKey(),
        'message' => 'Sara here. My memoir is finished, how much to publish it? Ring 07700 900123 today.',
    ])->lead->refresh();

    expect($lead->heat)->toBe('hot')
        ->and($lead->heat_reason)->toBe('Manuscript finished and asks for a price today')
        ->and($lead->heat_at)->not->toBeNull()
        ->and(assistantHeard())->toContain('My memoir is finished', 'UK Publishing House')
        ->not->toContain('Sara')->not->toContain('07700')->not->toContain('willsdon.example');

    // The list says so beside the name; a cold lead carries no tag there.
    Livewire::actingAs($this->manager)->test(LeadsIndex::class, ['brand' => $this->brand])->assertSeeHtml('lq-heat-hot');

    assistantSays('COLD | Says nothing about what they want');
    $quiet = app(CreateLead::class)->handle($this->brand, ['name' => 'Old Import', 'email' => 'old@example.com', 'message' => 'Hello', 'silent' => true])->lead->refresh();
    $empty = app(CreateLead::class)->handle($this->brand, ['name' => 'No Words', 'email' => 'nowords@example.com'])->lead->refresh();

    // An import is not rated, and neither is an enquiry with nothing to read.
    expect($quiet->heat)->toBeNull()->and($empty->heat)->toBeNull();
    Http::assertSentCount(1);
});

it('rates again from the lead for who works it, and leaves a lead untagged when the assistant is away', function (): void {
    $lead = ownLead($this, ['message' => 'Just looking around for now.']);
    assistantSays('warm - Interested but in no hurry.');

    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('Rate')
        ->call('rateHeat')
        ->assertSeeHtml('lq-heat-warm');

    expect($lead->refresh()->heat)->toBe('warm')->and($lead->heat_reason)->toBe('Interested but in no hurry');

    // A colleague only reads the lead.
    Livewire::actingAs($this->colleague)->test(LeadPanel::class, ['leadId' => $lead->getKey()])->assertDontSee('Rate again')->call('rateHeat')->assertForbidden();

    // Nonsense back, or Groq away: the old tag stays, nothing breaks.
    assistantSays('I cannot say.');
    expect(LeadInsights::rate($lead))->toBeNull()->and($lead->refresh()->heat)->toBe('warm');

    config(['liquify.ai.groq_key' => '']);
    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])->assertDontSee('Rate again')->call('rateHeat')->assertNotFound();
});

it('reads why a lead was lost, lets a person choose instead, and forgets it when the lead comes back', function (): void {
    $lost = LeadStatus::query()->forBrand($this->brand)->where('is_lost', true)->firstOrFail();
    $contacted = LeadStatus::query()->forBrand($this->brand)->where('slug', 'contacted')->firstOrFail();
    $lead = ownLead($this, ['message' => 'How much is editing?']);
    app(AddLeadNote::class)->handle($lead, 'Sara said £900 is far too much for her, went quiet after that.', $this->agent);

    assistantSays('price (Too expensive) | Said the quote was far too much');
    app(ChangeLeadStatus::class)->handle($lead, $lost, $this->agent);

    expect($lead->refresh()->lost_reason)->toBe('price')
        ->and($lead->lost_reason_note)->toBe('Said the quote was far too much')
        ->and($lead->lost_reason_by)->toBeNull()
        ->and(assistantHeard())->toContain('far too much for her')->not->toContain('Sara');

    $panel = Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('Why it was lost')->assertSee('Too expensive: Said the quote was far too much')->assertSee('Read by AI');

    // The person knows better: theirs stays, also when the assistant is asked again.
    $panel->call('setLostReason', 'elsewhere')->assertSee('Went with someone else')->assertDontSee('Read by AI');
    assistantSays('no_reply | Stopped answering');
    LeadInsights::whyLost($lead->refresh());

    expect($lead->refresh()->lost_reason)->toBe('elsewhere')->and($lead->lost_reason_by)->toBe($this->agent->getKey());

    $panel->call('setLostReason', 'made-up')->assertNotFound();
    Livewire::actingAs($this->colleague)->test(LeadPanel::class, ['leadId' => $lead->getKey()])->call('setLostReason', 'price')->assertForbidden();

    // The dashboard counts it for whoever works the lead.
    Cache::flush();
    Livewire::actingAs($this->agent)->test(BrandDashboard::class, ['brand' => $this->brand])->assertSee('Why leads were lost')->assertSee('Went with someone else');

    // Back in the pipeline: no reason any more, and no "why" on the lead.
    app(ChangeLeadStatus::class)->handle($lead, $contacted, $this->agent);
    expect($lead->refresh()->lost_reason)->toBeNull()->and($lead->lost_reason_by)->toBeNull();
    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])->assertDontSee('Why it was lost');
});

it('offers a follow-up when a note names a day, and only the person adds it', function (): void {
    $lead = ownLead($this);
    $this->travelTo(now()->setDate(2026, 11, 16)->setTime(9, 0));
    $panel = Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()]);

    // A note with no word about time never leaves.
    $panel->set('note', 'Sent her the price list by email.')->call('addNote')->assertSet('followUpHint', null);
    expect(LeadInsights::mentionsTime('Sent her the price list by email.'))->toBeFalse();
    Http::assertNothingSent();

    assistantSays('2026-11-20 15:00 | Call Sara back about the quote');
    $panel->set('note', 'Spoke to Sara, call her back Friday 3pm about the quote on 07700 900123.')->call('addNote');
    $note = LeadNote::query()->latest('id')->firstOrFail();

    $panel->call('suggestFollowUp', $note->getKey())
        ->assertSee('Add a follow-up?')->assertSee('Call Sara back about the quote')->assertSee('Fri 20 Nov, 3:00 PM');
    expect(assistantHeard())->toContain('call her back Friday 3pm', 'Monday 16 November 2026')->not->toContain('Sara')->not->toContain('07700');

    // Nothing is saved until the person saves the usual form, filled in for them.
    expect($lead->tasks()->count())->toBe(0);
    $panel->call('useFollowUpHint')
        ->assertSet('showFollowUp', true)->assertSet('followUpAt', '2026-11-20T15:00')->assertSet('followUpTitle', 'Call Sara back about the quote')->assertSet('followUpHint', null)
        ->call('scheduleFollowUp');
    expect($lead->tasks()->sole()->title)->toBe('Call Sara back about the quote');

    // A day already past (and no weekday in the note to go by): nothing is offered.
    assistantSays('2026-11-10 10:00 | Check the payment');
    expect(LeadInsights::followUpIn('She pays on the 10th, check then.', $lead, $this->agent))->toBeNull();

    // No day named, or nonsense: nothing is offered.
    foreach (['NONE', 'Sometime soon'] as $answer) {
        assistantSays($answer);
        $panel->call('suggestFollowUp', $note->getKey())->assertSet('followUpHint', null)->assertDontSee('Add a follow-up?');
    }

    $panel->set('followUpHint', ['at' => 'not a date', 'title' => 'x', 'label' => 'x'])->call('useFollowUpHint')->assertSet('followUpTitle', 'Follow up');
    Livewire::actingAs($this->colleague)->test(LeadPanel::class, ['leadId' => $lead->getKey()])->call('suggestFollowUp', $note->getKey())->assertForbidden();
});

it('keeps a summary as a note, from the lead and from its chat', function (): void {
    $lead = ownLead($this, ['message' => 'My memoir is ready.']);
    assistantSays("- Wants her memoir published.\n- Next: send a quote.");

    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->call('summarise')->assertSee('Save as a note')
        ->call('saveSummaryAsNote')
        ->assertDontSee('Summary of this lead');

    expect($lead->notes()->sole()->body)->toBe("Summary (written by AI):\n- Wants her memoir published.\n- Next: send a quote.")
        ->and($lead->notes()->sole()->user_id)->toBe($this->agent->getKey());

    $chat = Conversation::query()->create(['brand_id' => $this->brand->getKey(), 'lead_id' => $lead->getKey(), 'channel' => Conversation::CHANNEL_MESSENGER, 'contact_external_id' => 'psid-1', 'contact_name' => 'Sara Willsdon', 'last_message_at' => now(), 'last_inbound_at' => now()]);
    Message::query()->create(['conversation_id' => $chat->getKey(), 'direction' => Message::IN, 'body' => 'Can you publish my memoir by March?']);

    Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->call('summarise')->assertSee('Save as a note on the lead')
        ->call('saveSummaryAsNote')
        ->assertDontSee('Summary of this chat');

    expect($lead->notes()->count())->toBe(2)
        ->and($lead->notes()->latest('id')->first()->body)->toStartWith('Summary of the Messenger chat (written by AI):');

    // A colleague reads the chat and its summary, but writes nothing on the lead.
    Livewire::actingAs($this->colleague)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->call('summarise')->assertDontSee('Save as a note on the lead')
        ->call('saveSummaryAsNote')->assertForbidden();
});

it('does nothing by itself without a key', function (): void {
    config(['liquify.ai.groq_key' => '']);
    $lost = LeadStatus::query()->forBrand($this->brand)->where('is_lost', true)->firstOrFail();

    $lead = app(CreateLead::class)->handle($this->brand, ['name' => 'Sara Willsdon', 'email' => 'sara@willsdon.example', 'message' => 'How much?', 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()])->lead;
    app(ChangeLeadStatus::class)->handle($lead, $lost, $this->agent);

    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        // The reason can still be chosen by hand.
        ->assertSee('Why it was lost')->assertSee('Not said yet')
        ->set('note', 'Call her back on Friday.')->call('addNote')
        ->call('setLostReason', 'not_ready')->assertSee('Not ready yet');

    expect($lead->refresh()->heat)->toBeNull()->and($lead->lost_reason)->toBe('not_ready');
    Http::assertNothingSent();
});

it('takes the weekday from the note when the assistant gets the calendar wrong', function (): void {
    $lead = ownLead($this);
    $this->actingAs($this->agent);
    $this->travelTo(now()->setDate(2026, 11, 16)->setTime(9, 0));

    // Thursday is the 19th; the assistant answered Friday the 20th.
    assistantSays('2026-11-20 16:00 | Ring back about the cover');
    $found = LeadInsights::followUpIn('She asked me to ring back Thursday at 4pm about the cover.', $lead, $this->agent);

    expect($found['at']->format('D Y-m-d H:i'))->toBe('Thu 2026-11-19 16:00')
        ->and($found['title'])->toBe('Ring back about the cover')
        ->and(assistantHeard())->toContain('Thu 19 Nov = 2026-11-19');

    // Two days named: the assistant's answer stands.
    assistantSays('2026-11-21 10:00 | Try again');
    expect(LeadInsights::followUpIn('Spoke Monday, she was busy. Try Saturday.', $lead, $this->agent)['at']->format('D Y-m-d'))->toBe('Sat 2026-11-21');
});
