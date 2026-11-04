<?php

declare(strict_types=1);

use App\Modules\Chat\Livewire\App\CannedRepliesIndex;
use App\Modules\Chat\Models\CannedReply;
use App\Modules\Chat\Models\ChatAttachment;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Leads\Actions\DeleteLeadForever;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Live chat, third update (3 Nov 2026 plan): files in both directions and the team's saved replies, and the clean-up
 * the privacy page promises.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    Cache::flush();

    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department, ['name' => 'Farah Front']);
    $this->agent = agentOf($this->department, ['name' => 'Sami Seller']);
    $this->colleague = agentOf($this->department, ['name' => 'Cara Colleague']);
    $this->admin = admin();
    $this->widget = ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie']]);

    inBrand($this->brand);
});

/** A visitor opens the chat box and writes; returns their headers and the chat. */
function extraVisitor(object $test, string $text = 'Hello there'): array
{
    $init = $test->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $test->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')];
    $test->postJson('/chat/api/messages', ['body' => $text], $headers)->assertOk();

    return [$headers, Conversation::query()->latest('id')->firstOrFail()];
}

function pdfFile(string $name = 'brief.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

it('takes a picture and a PDF from a visitor, keeps them private, and serves them through signed links', function (): void {
    [$headers, $chat] = extraVisitor($this);

    $picture = $this->post('/chat/api/upload', ['file' => UploadedFile::fake()->image('cover.png', 300, 200), 'caption' => 'My cover idea'], $headers + ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('message.from', 'visitor')
        ->assertJsonPath('message.body', 'My cover idea')
        ->assertJsonPath('message.files.0.name', 'cover.png')
        ->assertJsonPath('message.files.0.image', true);
    $this->post('/chat/api/upload', ['file' => pdfFile('manuscript-notes.pdf')], $headers + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('message.files.0.image', false);

    $files = ChatAttachment::query()->orderBy('id')->get();
    expect($files)->toHaveCount(2)
        ->and($files[0]->mime)->toBe('image/png')
        ->and($files[1]->mime)->toBe('application/pdf')
        ->and($files[0]->path)->not->toContain('cover')
        ->and($files[0]->path)->toStartWith('chat/'.$this->brand->getKey().'/');
    Storage::disk('local')->assertExists($files[0]->path);
    expect($chat->fresh()->last_message_preview)->toBe('File: manuscript-notes.pdf');

    // The picture opens in place for whoever holds the signed link; the PDF downloads; nothing else is let through.
    $link = $picture->json('message.files.0.url');
    $shown = $this->get($link)->assertOk();
    expect($shown->headers->get('Content-Type'))->toBe('image/png')
        ->and($shown->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($shown->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($shown->headers->get('Content-Disposition'))->toStartWith('inline');
    expect($this->get($this->getJson('/chat/api/messages', $headers)->json('messages.2.files.0.url'))->assertOk()->headers->get('Content-Disposition'))->toStartWith('attachment');
    $this->get('/chat/files/'.$files[0]->getKey())->assertForbidden();
    $this->get($link.'x')->assertForbidden();

    // The team sees them in the Inbox, signed in: a Manager and a colleague yes, somebody of another brand no.
    $url = '/b/dpp/chat-files/'.$files[0]->getKey();
    $this->actingAs($this->manager)->get($url)->assertOk();
    $this->flushSession();
    $this->actingAs($this->colleague)->get($url)->assertOk();
    $this->flushSession();
    $this->actingAs(agentOf(department(brand(['slug' => 'ukph']), ['name' => 'Sales'])))->get($url)->assertNotFound();
    $this->flushSession();
    $this->get($url)->assertNotFound();
});

it('refuses files that are not what they say, are too big, or are not on the list', function (): void {
    [$headers] = extraVisitor($this);
    // Eight uploads in a row would meet the rate limit (six a minute): it is cleared between them.
    $send = function (UploadedFile $file) use ($headers) {
        Cache::flush();

        return $this->post('/chat/api/upload', ['file' => $file], $headers + ['Accept' => 'application/json']);
    };

    // A program or a web page with a harmless name, a kind that is not on the list, an empty file.
    $send(UploadedFile::fake()->createWithContent('invoice.pdf', '<?php echo shell_exec($_GET["c"]);'))->assertStatus(422)->assertJsonPath('error', fn (string $text): bool => str_contains($text, 'not what its name says'));
    $send(UploadedFile::fake()->createWithContent('picture.png', '<html><script>alert(1)</script></html>'))->assertStatus(422);
    $send(UploadedFile::fake()->createWithContent('notes.txt', '<script>alert(1)</script>'))->assertStatus(422);
    $send(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422)->assertJsonPath('error', fn (string $text): bool => str_contains($text, 'not accepted'));
    $send(UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'))->assertStatus(422);
    $send(UploadedFile::fake()->createWithContent('page.html', '<html></html>'))->assertStatus(422);
    $send(UploadedFile::fake()->createWithContent('empty.txt', ''))->assertStatus(422)->assertJsonPath('error', 'That file is empty.');

    // The widget's own size limit.
    $this->widget->update(['appearance' => ['max_upload_mb' => '1']]);
    $send(UploadedFile::fake()->create('big.pdf', 1500, 'application/pdf'))->assertStatus(422)->assertJsonPath('error', 'That file is too big. The most we take is 1 MB.');

    expect(ChatAttachment::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

    // Text and a Word-style name pass when the content agrees.
    $send(UploadedFile::fake()->createWithContent('notes.txt', "Chapter one\nIt was a dark night."))->assertOk();
    expect(ChatAttachment::query()->sole()->mime)->toBe('text/plain');
});

it('lets the team send a file with or without words, and the visitor gets it', function (): void {
    [$headers, $chat] = extraVisitor($this);

    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->set('upload', UploadedFile::fake()->image('proof.jpg', 400, 300))
        ->set('reply', 'Here is the proof')
        ->call('send')
        ->assertHasNoErrors()
        ->set('upload', pdfFile('price-list.pdf'))
        ->call('send')
        ->assertHasNoErrors();

    $messages = Message::query()->where('kind', 'file')->orderBy('id')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->body)->toBe('Here is the proof')
        ->and($messages[1]->body)->toBeNull()
        ->and($messages[1]->direction)->toBe('out')
        ->and($chat->fresh()->last_message_preview)->toBe('File: price-list.pdf');

    $seen = $this->getJson('/chat/api/messages', $headers)->assertOk()->json('messages');
    expect(array_column($seen, 'from'))->toBe(['visitor', 'team', 'team'])
        ->and($seen[1]['files'][0]['name'])->toBe('proof.jpg')
        ->and($seen[2]['files'][0]['name'])->toBe('price-list.pdf');
    $this->get($seen[2]['files'][0]['url'])->assertOk();

    // The team sees the card and the picture in the thread.
    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])->assertSee('price-list.pdf')->assertSee('proof.jpg');

    // A note never carries a file; and a file that is not allowed is refused with words.
    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->set('mode', 'note')->set('upload', pdfFile('secret.pdf'))->set('reply', 'Internal note')->call('send');
    expect(ChatAttachment::query()->count())->toBe(2);

    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->set('upload', UploadedFile::fake()->createWithContent('run.pdf', 'MZ not a pdf'))->call('send')->assertHasErrors('reply');
});

it('gives each person the saved replies of the company, the brand and their own, with the names filled in', function (): void {
    $global = CannedReply::query()->create(['scope' => 'global', 'shortcut' => '/hello', 'title' => 'Greeting', 'body' => 'Hi {visitor_name}, this is {agent_name} from {brand_name}.']);
    CannedReply::query()->create(['scope' => 'brand', 'brand_id' => $this->brand->getKey(), 'shortcut' => '/price', 'title' => 'Price', 'body' => 'Our memoir package starts at 1,200.']);
    CannedReply::query()->create(['scope' => 'brand', 'brand_id' => brand(['slug' => 'ukph'])->getKey(), 'shortcut' => '/ukonly', 'title' => 'Other brand', 'body' => 'x']);
    CannedReply::query()->create(['scope' => 'personal', 'user_id' => $this->agent->getKey(), 'shortcut' => '/mine', 'title' => 'Mine', 'body' => 'My own words']);
    CannedReply::query()->create(['scope' => 'personal', 'user_id' => $this->colleague->getKey(), 'shortcut' => '/hers', 'title' => 'Hers', 'body' => 'Her own words']);

    [$headers, $chat] = extraVisitor($this);
    $this->postJson('/chat/api/details', ['name' => 'Roy Bleicher', 'email' => 'roy@example.com'], $headers)->assertOk();

    $shortcuts = fn ($user) => collect(Livewire::actingAs($user)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])->viewData('savedReplies'));

    expect($shortcuts($this->agent)->pluck('shortcut')->all())->toBe(['/hello', '/mine', '/price'])
        ->and($shortcuts($this->colleague)->pluck('shortcut')->all())->toBe(['/hello', '/hers', '/price'])
        ->and($shortcuts($this->agent)->firstWhere('shortcut', '/hello')['body'])->toBe('Hi Roy, this is Sami from Dublin Publishing Press.');

    // A visitor with no name is "there".
    expect($global->filled('Visitor 12', 'Sami Seller', 'DPP'))->toBe('Hi there, this is Sami from DPP.')
        ->and($global->filled(null, '', 'DPP'))->toBe('Hi there, this is the team from DPP.');
});

it('lets an Agent save their own replies, a Manager the brand\'s, and an Admin the company\'s', function (): void {
    $screen = fn ($user) => Livewire::actingAs($user)->test(CannedRepliesIndex::class, ['brand' => $this->brand]);

    $screen($this->agent)->call('create')->set('scope', 'personal')->set('shortcut', 'Price')->set('title', 'Price list')->set('body', 'Hi {visitor_name}')->call('save')->assertHasNoErrors();
    $mine = CannedReply::query()->sole();
    expect($mine->shortcut)->toBe('/price')->and($mine->scope)->toBe('personal')->and($mine->user_id)->toBe($this->agent->getKey());

    // Same shortcut again is refused; an Agent cannot write for the brand or the company.
    $screen($this->agent)->call('create')->set('shortcut', '/price')->set('title', 'Again')->set('body', 'x')->call('save')->assertHasErrors('shortcut');
    $screen($this->agent)->call('create')->set('scope', 'brand')->set('shortcut', '/team')->set('title', 'Team')->set('body', 'x')->call('save')->assertForbidden();
    $screen($this->agent)->call('create')->set('scope', 'global')->set('shortcut', '/all')->set('title', 'All')->set('body', 'x')->call('save')->assertForbidden();
    $screen($this->agent)->call('create')->set('shortcut', 'bad word!')->set('title', 'Bad')->set('body', 'x')->call('save')->assertHasErrors('shortcut');

    $screen($this->manager)->call('create')->set('scope', 'brand')->set('shortcut', '/hours')->set('title', 'Hours')->set('body', 'We are here 9 to 5.')->call('save')->assertHasNoErrors();
    $screen($this->manager)->call('create')->set('scope', 'global')->set('shortcut', '/all')->set('title', 'All')->set('body', 'x')->call('save')->assertForbidden();
    $screen($this->admin)->call('create')->set('scope', 'global')->set('shortcut', '/all')->set('title', 'All brands')->set('body', 'Thank you for writing.')->call('save')->assertHasNoErrors();

    expect(CannedReply::query()->count())->toBe(3)
        ->and(CannedReply::query()->where('scope', 'brand')->sole()->brand_id)->toBe($this->brand->getKey());

    // Who may change what: your own, the brand's for a Manager, the company's for an Admin only.
    $brandReply = CannedReply::query()->where('scope', 'brand')->sole();
    $global = CannedReply::query()->where('scope', 'global')->sole();
    $screen($this->agent)->call('edit', $brandReply->getKey())->assertForbidden();
    $screen($this->colleague)->call('edit', $mine->getKey())->assertNotFound();
    $screen($this->manager)->call('edit', $global->getKey())->assertForbidden();
    $screen($this->agent)->call('edit', $mine->getKey())->set('body', 'New words')->call('save')->assertHasNoErrors();
    expect($mine->fresh()->body)->toBe('New words');

    $screen($this->manager)->call('remove', $brandReply->getKey());
    $screen($this->agent)->call('remove', $global->getKey())->assertForbidden();
    expect(CannedReply::query()->count())->toBe(2);

    // The menu has it for everybody in the brand.
    $this->actingAs($this->agent)->get('/b/dpp/saved-replies')->assertOk()->assertSee('Saved replies')->assertSee('/price');
});

it('clears old files and the chats of visitors who never gave details, and keeps the rest', function (): void {
    // A visitor who gave nothing, 20 days ago, with a file.
    [$headersOld, $oldChat] = extraVisitor($this, 'Old question');
    $this->post('/chat/api/upload', ['file' => pdfFile('old.pdf')], $headersOld + ['Accept' => 'application/json'])->assertOk();
    DB::table('conversations')->where('id', $oldChat->getKey())->update(['last_message_at' => now()->subDays(20)]);

    // A visitor who gave nothing, today.
    [, $freshChat] = extraVisitor($this, 'Fresh question');

    // A visitor who became a lead: the chat stays, but a file 200 days old goes.
    [$headersLead, $leadChat] = extraVisitor($this, 'Lead question');
    $this->postJson('/chat/api/details', ['email' => 'roy@example.com'], $headersLead)->assertOk();
    $this->post('/chat/api/upload', ['file' => pdfFile('lead.pdf')], $headersLead + ['Accept' => 'application/json'])->assertOk();
    $leadFile = ChatAttachment::query()->whereHas('message', fn ($message) => $message->where('conversation_id', $leadChat->getKey()))->sole();
    DB::table('chat_attachments')->where('id', $leadFile->getKey())->update(['created_at' => now()->subDays(200)]);
    DB::table('conversations')->where('id', $leadChat->getKey())->update(['last_message_at' => now()->subDays(30)]);

    // A visitor who only looked, a month ago.
    ChatVisitor::query()->create(['brand_id' => $this->brand->getKey(), 'visitor_token' => 'looker-'.uniqid(), 'last_seen_at' => now()->subDays(30), 'first_seen_at' => now()->subDays(30)]);

    $oldPath = ChatAttachment::query()->whereHas('message', fn ($message) => $message->where('conversation_id', $oldChat->getKey()))->sole()->path;
    $leadPath = $leadFile->path;

    Artisan::call('chat:prune');

    expect(Conversation::query()->find($oldChat->getKey()))->toBeNull()
        ->and(Conversation::query()->find($freshChat->getKey()))->not->toBeNull()
        ->and(Conversation::query()->find($leadChat->getKey()))->not->toBeNull()
        ->and(ChatAttachment::query()->count())->toBe(0)
        ->and(ChatVisitor::query()->where('visitor_token', 'like', 'looker-%')->count())->toBe(0)
        ->and(ChatVisitor::query()->count())->toBe(2)
        ->and(Message::query()->where('conversation_id', $oldChat->getKey())->count())->toBe(0);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertMissing($leadPath);
    $this->assertDatabaseHas('activity_log', ['event' => 'chat.pruned']);

    // The lead's chat still shows the message, and says the file was removed.
    $this->getJson('/chat/api/messages', $headersLead)->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.files', []);
});

it('removes the files, visitor and traces of a website chat when its lead is deleted for good', function (): void {
    [$headers, $chat] = extraVisitor($this, 'Hello, my name is Roy');
    $this->postJson('/chat/api/details', ['name' => 'Roy Bleicher', 'email' => 'roy@example.com'], $headers)->assertOk();
    $this->post('/chat/api/upload', ['file' => pdfFile('manuscript.pdf')], $headers + ['Accept' => 'application/json'])->assertOk();

    // The team works the chat, so its log lines name the visitor.
    Livewire::actingAs($this->admin)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])->call('close');

    $lead = Lead::query()->sole();
    $path = ChatAttachment::query()->sole()->path;
    Storage::disk('local')->assertExists($path);
    $this->assertDatabaseHas('activity_log', ['event' => 'chat.closed', 'subject_id' => $chat->getKey()]);

    $lead->delete();
    app(DeleteLeadForever::class)->handle(Lead::withTrashed()->findOrFail($lead->getKey()), $this->admin);

    expect(Conversation::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(ChatAttachment::query()->count())->toBe(0)
        ->and(ChatVisitor::query()->withoutGlobalScopes()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
    $this->assertDatabaseMissing('activity_log', ['event' => 'chat.closed', 'subject_id' => $chat->getKey()]);
});
