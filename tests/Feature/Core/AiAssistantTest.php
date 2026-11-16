<?php

declare(strict_types=1);

use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Core\Support\Ai\Ai;
use App\Modules\Core\Support\Ai\AiUnavailable;
use App\Modules\Core\Support\Ai\ContactMask;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The assistant (user request, 16 Nov 2026: "integrate Groq free AI", team helpers first): suggest a reply, fix the
 * writing, translate, and summarise a chat or a lead. It writes for the person who asked and sends nothing; names,
 * email addresses and phone numbers never leave; without a key none of it is there; at a limit it says "busy".
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();

    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department, ['name' => 'Farah Front']);
    $this->agent = agentOf($this->department, ['name' => 'Sami Seller']);
    $this->colleague = agentOf($this->department, ['name' => 'Cara Colleague']);
    $this->widget = ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie']]);

    inBrand($this->brand);
    config(['liquify.ai.groq_key' => 'gsk_test_key', 'liquify.ai.groq_models' => 'big-model,small-model']);
});

/** A website visitor writes; returns the chat. */
function chatFromVisitor(object $test, string ...$texts): Conversation
{
    $init = $test->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $test->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')];

    foreach ($texts as $text) {
        $test->postJson('/chat/api/messages', ['body' => $text], $headers)->assertOk();
    }

    return Conversation::query()->latest('id')->firstOrFail();
}

/** Groq answering with these words. */
function groqSays(string $words): void
{
    // The first stand-in registered keeps answering, so it reads the words wanted now.
    if (! app()->bound('test.groq.faked')) {
        Http::fake(['api.groq.com/*' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => app('test.groq.words')]]]])]);
        app()->instance('test.groq.faked', true);
    }

    app()->instance('test.groq.words', $words);
}

/** Everything that went to Groq in the last request, as one text. */
function sentToGroq(): string
{
    $sent = '';
    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return true;
    });

    return (string) $sent;
}

it('hides email addresses, phone numbers and names, and puts them back in the answer', function (): void {
    $mask = new ContactMask(['Aoife Byrne', 'Visitor']);
    $hidden = $mask->hide('Hi, aoife here. Call me on 087 123 4567 or +353 (87) 123-4567, or write to aoife.byrne@example.ie. Byrne is my surname. Order 2026-11-16, 12 copies.');

    expect($hidden)->not->toContain('aoife')->not->toContain('Byrne')->not->toContain('087')->not->toContain('example.ie')
        ->toContain('[name', '[phone1]', '[phone2]', '[email1]')
        // A date and a small number are not contacts.
        ->toContain('2026-11-16', '12 copies')
        ->and($mask->show('Thanks [name1], we will ring [phone1] and write to [email1].'))->toBe('Thanks aoife, we will ring 087 123 4567 and write to aoife.byrne@example.ie.');

    // A name that is also a word is hidden only with its capital; a visitor with no name hides nothing.
    $will = new ContactMask(['Will May']);
    expect($will->hide('Will you call? I will wait until May.'))->toBe('[name1] you call? I will wait until [name2].')
        ->and((new ContactMask(['Visitor']))->hide('Visitor numbers are up'))->toBe('Visitor numbers are up')
        // A placeholder the assistant made up is shown as plain words.
        ->and($will->show('[name1] and [name7] gave [phone3]'))->toBe('Will and the customer gave their phone number');
});

it('suggests a reply for who may answer, with the contact hidden on the way out', function (): void {
    $chat = chatFromVisitor($this, 'Hello, do you publish memoirs?', 'You can reach me on 087 123 4567 or niamh@example.ie', 'Niamh here again, any news?');
    $chat->forceFill(['contact_name' => 'Niamh Kelly'])->save();
    groqSays('"Hi [name1], yes we publish memoirs. We will ring you on [phone1] today."');

    $answer = Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->assertSee('AI help')
        ->call('assist', 'suggest')
        ->assertReturned(fn (array $answer): bool => ($answer['text'] ?? '') === 'Hi Niamh, yes we publish memoirs. We will ring you on 087 123 4567 today.');

    $sent = sentToGroq();
    expect($sent)->toContain('Customer: Hello, do you publish memoirs?', 'Dublin Publishing Press', '[phone1]', '[email1]', '"model":"big-model"')
        ->not->toContain('087 123')->not->toContain('niamh@')->not->toContain('Niamh');

    // Nothing was sent to the visitor: the words are only handed to the person's message box.
    expect(Message::query()->where('direction', Message::OUT)->count())->toBe(0);
    Http::assertSentCount(1);
});

it('fixes and translates a draft, and asks for the draft first', function (): void {
    $chat = chatFromVisitor($this, 'Bonjour, publiez-vous des romans ?');
    $thread = Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true]);

    $thread->call('assist', 'improve', '  ')->assertReturned(fn (array $answer): bool => ($answer['error'] ?? '') === 'Write your reply first.');
    Http::assertNothingSent();

    groqSays('Yes, we publish novels. Could you tell me more about yours?');
    $thread->call('assist', 'improve', 'yes we publsh novels can u tell me more abt yours, mail me at farah@example.com')
        ->assertReturned(fn (array $answer): bool => ($answer['text'] ?? '') === 'Yes, we publish novels. Could you tell me more about yours?');
    expect(sentToGroq())->toContain('yes we publsh novels', '[email1]')->not->toContain('farah@example.com');

    groqSays('Oui, nous publions des romans.');
    $thread->call('assist', 'translate', 'Yes, we publish novels.')->assertReturned(fn (array $answer): bool => ($answer['text'] ?? '') === 'Oui, nous publions des romans.');
    expect(sentToGroq())->toContain('Customer: Bonjour, publiez-vous des romans ?', 'Yes, we publish novels.');

    // Only the three jobs exist.
    $thread->call('assist', 'delete-everything')->assertNotFound();
});

it('summarises a chat for whoever may read it, and never sends the team\'s notes', function (): void {
    $chat = chatFromVisitor($this, 'I have a finished memoir of 80,000 words.');
    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->set('reply', 'Lovely. We can publish it in six weeks.')->call('send')
        ->set('mode', 'note')->set('reply', 'Secret note: she haggles')->call('send');

    groqSays("- Wants a finished memoir published.\n- The team said six weeks.");

    Livewire::actingAs($this->colleague)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->call('summarise')
        ->assertSee('Summary of this chat')->assertSee('The team said six weeks.')
        ->call('closeSummary')
        ->assertDontSee('Summary of this chat');

    expect(sentToGroq())->toContain('Customer: I have a finished memoir', 'Team: Lovely. We can publish it in six weeks.')->not->toContain('haggles');
});

it('keeps the box to who may reply, and shows nothing without a key', function (): void {
    $chat = chatFromVisitor($this, 'Hello?');
    // Answered by the Manager: the chat is theirs, so a colleague only reads it.
    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])->set('reply', 'Hi!')->call('send');
    groqSays('Anything');

    Livewire::actingAs($this->colleague)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->assertDontSee('AI help')
        ->call('assist', 'suggest')->assertForbidden();

    config(['liquify.ai.groq_key' => '']);
    expect(Ai::enabled())->toBeFalse();

    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->assertDontSee('AI help')->assertDontSee('Summarise this chat')
        ->call('assist', 'suggest')->assertNotFound();

    Http::assertNothingSent();
});

it('moves to the next model at a limit, rests the first, and says busy when all are', function (): void {
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'Rate limit reached']], 429, ['retry-after' => '30'])
        ->push(['choices' => [['message' => ['content' => 'From the small model']]]])
        ->push(['choices' => [['message' => ['content' => 'Small again']]]])
        ->push(['error' => 'slow down'], 429, ['retry-after' => '30']),
    ]);

    expect(Ai::ask('Be brief.', 'Hello', $this->manager))->toBe('From the small model');
    // The first model rests: the next question goes straight to the second.
    expect(Ai::ask('Be brief.', 'Hello', $this->manager))->toBe('Small again');
    Http::assertSentCount(3);

    expect(fn () => Ai::ask('Be brief.', 'Hello', $this->manager))->toThrow(AiUnavailable::class, 'The assistant is busy right now. Try again in a minute.');
    // Both rest now: nothing more is asked of Groq.
    expect(fn () => Ai::ask('Be brief.', 'Hello', $this->manager))->toThrow(AiUnavailable::class);
    Http::assertSentCount(4);
});

it('stops one person asking again and again, and the whole CRM at the day\'s cap', function (): void {
    groqSays('Fine');

    foreach (range(1, 8) as $ignored) {
        Ai::ask('Be brief.', 'Hello', $this->agent);
    }

    expect(fn () => Ai::ask('Be brief.', 'Hello', $this->agent))->toThrow(AiUnavailable::class, 'You have asked the assistant a lot in the last minute.')
        ->and(Ai::askedToday())->toBe(8);

    config(['liquify.ai.daily_cap' => 9]);
    Ai::ask('Be brief.', 'Hello', $this->manager);
    expect(fn () => Ai::ask('Be brief.', 'Hello', $this->manager))->toThrow(AiUnavailable::class, 'The assistant has answered as much as it may today.');
    Http::assertSentCount(9);
});

it('summarises a lead from what it shows, without the contact', function (): void {
    $lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(),
        'name' => 'Sara Willsdon', 'email' => 'sara@willsdon.example', 'phone' => '+447700900123',
        'message' => 'I am Sara, my memoir is ready. Ring 07700 900123.',
        'fields' => ['service' => 'Book Editing & Proofreading', 'tell_us_about_your_book' => 'memoir'],
    ]);
    app(AddLeadNote::class)->handle($lead, 'Spoke to Sara on 07700 900123, she wants a quote by Friday.', $this->agent);

    groqSays("- [name1] wants her memoir edited.\n- A quote is due by Friday.");

    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('Summarise')
        ->call('summarise')
        ->assertSee('Summary of this lead')->assertSee('Sara wants her memoir edited.')
        ->call('closeSummary')
        ->assertDontSee('Summary of this lead');

    expect(sentToGroq())->toContain('Book Editing', 'she wants a quote by Friday', 'my memoir is ready')
        ->not->toContain('Sara')->not->toContain('Willsdon')->not->toContain('07700')->not->toContain('willsdon.example');

    config(['liquify.ai.groq_key' => '']);
    Livewire::actingAs($this->agent)->test(LeadPanel::class, ['leadId' => $lead->getKey()])->assertDontSee('Summarise')->call('summarise')->assertNotFound();
});

it('asks the thinking models to think little, and leaves alone a model Groq no longer has', function (): void {
    config(['liquify.ai.groq_models' => 'gone-model,openai/gpt-oss-120b']);
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'The model does not exist']], 404)
        ->push(['choices' => [['message' => ['content' => 'Hello']]]])
        ->push(['choices' => [['message' => ['content' => 'Hello again']]]]),
    ]);

    expect(Ai::ask('Be brief.', 'Hello', $this->manager, 100))->toBe('Hello')
        ->and(Ai::ask('Be brief.', 'Hello', $this->manager, 100))->toBe('Hello again');

    // The missing model was asked once; the thinking model gets room to think and keeps its thinking to itself.
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'openai/gpt-oss-120b' && $request['reasoning_effort'] === 'low' && $request['include_reasoning'] === false && $request['max_completion_tokens'] === 700);
});
