<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Actions\MergeLeads;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadNote;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Models\Task;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Leads\Support\LeadEnquiry;
use App\Modules\Leads\Support\LeadMatcher;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * A lead's form answers in view, assigning with one click from the list and
 * the lead, and one person as one lead (twins, merge).
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department, ['name' => 'Vimal Agent']);
    $this->admin = admin();

    inBrand($this->brand);
});

it('shows what the person asked for up front, in words', function (): void {
    $lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(),
        'source_type' => LeadSource::Facebook,
        'name' => 'Sara Willsdon',
        'message' => null,
        'fields' => [
            'facebook_page' => 'UK Publishing House Ltd.',
            'form' => 'Form 1095805616376535',
            'campaign' => 'UPH - LEAD - ABO',
            'platform' => 'fb',
            'what_stage_is_your_book_at_right_now' => 'fully_written_and_ready_to_publish',
            'what_support_do_you_need' => 'full_publishing_package_—_everything_handled_for_me',
            'tell_us_about_your_book' => 'memoir',
            'what_brings_you_here_today' => 'i_have_a_finished_manuscript_and_i_am_ready_to_publish',
            'service' => 'Book Editing & Proofreading',
            'company' => 'Willsdon Ltd',
            'inbox_url' => 'https://business.facebook.com/latest/28269190652763012?nav_ref=thread_view_by_psid',
        ],
        'utm' => ['utm_source' => 'facebook'],
    ]);

    // Answers are read as a person wrote them; what is a machine name becomes a sentence.
    expect(LeadEnquiry::answers($lead))->toBe([
        ['label' => 'Service', 'value' => 'Book Editing & Proofreading'],
        ['label' => 'What stage is your book at right now?', 'value' => 'Fully written and ready to publish'],
        ['label' => 'What support do you need?', 'value' => 'Full publishing package — everything handled for me'],
        ['label' => 'Tell us about your book', 'value' => 'memoir'],
        ['label' => 'What brings you here today?', 'value' => 'I have a finished manuscript and I am ready to publish'],
    ])
        // Where it came from is kept apart from what was asked.
        ->and(array_column(LeadEnquiry::source($lead), 'value', 'label'))->toBe([
            'Facebook Page' => 'UK Publishing House Ltd.',
            'Form' => 'Form 1095805616376535',
            'Campaign' => 'UPH - LEAD - ABO',
            'Platform' => 'Facebook',
            'Campaign source' => 'facebook',
        ])
        ->and(LeadEnquiry::inboxUrl($lead))->toStartWith('https://business.facebook.com/latest/28269190652763012')
        ->and(LeadMatcher::messengerId($lead))->toBe('28269190652763012');

    Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        // In view without a click: there is no Message tab to open any more.
        ->assertSet('tab', 'notes')
        ->assertSee('What they asked for')
        ->assertSee('What stage is your book at right now?')
        ->assertSee('Fully written and ready to publish')
        ->assertSee('Where it came from')
        ->assertSee('Open their chat on Facebook')
        ->assertDontSee('Extra fields')
        ->assertDontSee('fully_written_and_ready_to_publish')
        ->assertDontSee('thread_view_by_psid</dd>', false)
        // A note is written on the Notes tab itself.
        ->call('openNote')
        ->assertDispatched('focus-note')
        ->call('addNote')
        ->assertHasErrors('note')
        ->set('note', 'Called her, she wants a quote by Friday')
        ->call('addNote')
        ->assertHasNoErrors()
        ->assertSet('note', '')
        ->assertSee('Called her, she wants a quote by Friday')
        // The history is one tab away.
        ->set('tab', 'timeline')
        ->assertSee('Note added');

    expect(LeadNote::query()->where('lead_id', $lead->getKey())->count())->toBe(1);

    // A lead with nothing but a name says so instead of showing an empty box.
    $bare = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'message' => null, 'fields' => null]);

    Livewire::actingAs($this->admin)->test(LeadPanel::class, ['leadId' => $bare->getKey()])->assertSee('no message and no answers')->assertDontSee('Where it came from');
});

it('assigns a lead with one click from the list and from the lead', function (): void {
    $consultants = department($this->brand, ['name' => 'Consultants']);
    $omar = agentOf($consultants, ['name' => 'Omar Other']);
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dean Williams']);

    // Somebody who only looks after clients has no lead screens, so leads are not offered to them.
    $upseller = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']), ['name' => 'Una Upseller']);

    // The menu offers every Agent who works leads, with the department each works in.
    expect(collect(app(AssignableAgents::class)->people($this->admin, $this->brand))->pluck('team', 'name')->all())
        ->toBe(['Omar Other' => 'Consultants', 'Vimal Agent' => 'Front Sales'])
        // A Manager is offered their own people only.
        ->and(array_column(app(AssignableAgents::class)->people($this->manager, $this->brand), 'name'))->toBe(['Vimal Agent']);

    $list = Livewire::actingAs($this->admin)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->assertSee('Assign this lead')
        // The icon that took a click on the row to the full page is gone.
        ->assertDontSee('lq-row-action', false)
        ->call('quickAssign', $lead->getKey(), $upseller->getKey());

    expect($lead->refresh()->assigned_to)->toBeNull();

    $list->call('quickAssign', $lead->getKey(), $this->agent->getKey())->assertDispatched('toast');

    // The lead follows its new owner into the department.
    expect($lead->refresh()->assigned_to)->toBe($this->agent->getKey())
        ->and($lead->department_id)->toBe($this->department->getKey());

    // Once it is in a department, only that department's people can be given it.
    $list->call('quickAssign', $lead->getKey(), $omar->getKey());
    expect($lead->refresh()->assigned_to)->toBe($this->agent->getKey());

    // The same menu moves it to another team.
    $list->call('quickSend', $lead->getKey(), $consultants->getKey());
    expect($lead->refresh()->department_id)->toBe($consultants->getKey());

    // From the lead itself: the Assign tile opens the people, one click gives it.
    $waiting = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey()]);

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $waiting->getKey()])
        ->assertSee('Assign')
        ->call('quickAssign', $omar->getKey())
        ->call('quickAssign', $this->agent->getKey());

    expect($waiting->refresh()->assigned_to)->toBe($this->agent->getKey());

    // An Agent sees no Owner column and cannot assign.
    Livewire::actingAs($this->agent)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->assertDontSee('Assign this lead')
        ->call('quickAssign', $waiting->getKey(), $this->agent->getKey())
        ->assertForbidden();
});

it('makes two leads of one person one, and keeps everything of both', function (): void {
    $form = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(),
        'source_type' => LeadSource::Facebook,
        'name' => 'Anthony Camilleri',
        'email' => 'antcamilleri@example.com',
        'phone' => null,
        'message' => null,
        'fields' => ['form' => 'Free consultation', 'what_type_of_book_is_it' => 'memoir_or_autobiography', 'inbox_url' => 'https://business.facebook.com/latest/900800700600500?nav_ref=thread_view_by_psid'],
        'created_at' => now()->subHours(5),
    ]);

    $chat = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(),
        'source_type' => LeadSource::Messenger,
        'name' => 'Anthony Camilleri',
        'email' => null,
        'phone' => null,
        'message' => 'Hello, I would like to publish my book',
        'fields' => ['facebook_page' => 'UKPH Page'],
        'department_id' => $this->department->getKey(),
        'assigned_to' => $this->agent->getKey(),
        'status_id' => LeadStatus::query()->forBrand($this->brand)->where('slug', 'contacted')->value('id'),
        'created_at' => now()->subHours(6),
    ]);

    $conversation = Conversation::query()->create(['brand_id' => $this->brand->getKey(), 'lead_id' => $chat->getKey(), 'contact_external_id' => '900800700600500', 'contact_name' => 'Anthony Camilleri']);
    app(AddLeadNote::class)->handle($chat, 'Replied on Messenger, waiting for the manuscript', $this->agent);
    app(ScheduleFollowUp::class)->handle($chat, now()->addDay(), 'Ask for the manuscript', $this->agent);

    $stranger = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Somebody Else']);

    // The form names the chat, so the two are known to be one person; a different person is not offered.
    $twins = LeadMatcher::twins($form, $this->admin);

    expect($twins)->toHaveCount(1)
        ->and($twins[0]['lead']->getKey())->toBe($chat->getKey())
        ->and($twins[0]['why'])->toBe('the same Messenger chat')
        ->and($twins[0]['sure'])->toBeTrue()
        ->and(LeadMatcher::twins($chat, $this->admin)[0]['lead']->getKey())->toBe($form->getKey())
        ->and(LeadMatcher::twins($stranger, $this->admin))->toBe([]);

    // An Agent is not offered a merge and cannot ask for one.
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $chat->getKey()])
        ->assertDontSee('This person has another lead')
        ->call('confirmMerge', $form->getKey())
        ->assertStatus(404);

    Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $form->getKey()])
        ->assertSee('This person has another lead')
        ->assertSee('the same Messenger chat')
        ->call('confirmMerge', $chat->getKey())
        ->assertSee('Merge the two leads of Anthony Camilleri?')
        ->call('runConfirmed')
        ->assertDontSee('This person has another lead')
        ->assertSee('Hello, I would like to publish my book');

    $form->refresh();

    expect(Lead::query()->forBrand($this->brand)->whereKey($chat->getKey())->exists())->toBeFalse()
        ->and(Lead::withTrashed()->forBrand($this->brand)->whereKey($chat->getKey())->exists())->toBeTrue()
        // The lead that stays keeps its own details and takes what it was missing.
        ->and($form->email)->toBe('antcamilleri@example.com')
        ->and($form->message)->toBe('Hello, I would like to publish my book')
        ->and($form->fields)->toMatchArray(['form' => 'Free consultation', 'facebook_page' => 'UKPH Page', 'what_type_of_book_is_it' => 'memoir_or_autobiography'])
        // Whoever worked the other lead, and how far it had come, carry over; it is as old as the older one.
        ->and($form->assigned_to)->toBe($this->agent->getKey())
        ->and($form->department_id)->toBe($this->department->getKey())
        ->and($form->status->slug)->toBe('contacted')
        ->and($form->created_at->getTimestamp())->toBe($chat->created_at->getTimestamp())
        // The chat, the note, the follow-up and the history moved with it.
        ->and($conversation->refresh()->lead_id)->toBe($form->getKey())
        ->and(LeadNote::query()->where('lead_id', $form->getKey())->count())->toBe(1)
        ->and(Task::query()->where('lead_id', $form->getKey())->count())->toBe(1)
        ->and($form->next_follow_up_at)->not->toBeNull()
        ->and(Activity::query()->where('subject_type', $form->getMorphClass())->where('subject_id', $form->getKey())->pluck('event')->all())
        ->toContain('leads.merged', 'leads.note_added', 'leads.follow_up_scheduled')
        ->and(Activity::query()->where('subject_type', $form->getMorphClass())->where('subject_id', $chat->getKey())->count())->toBe(0);

    // A lead that is won or lost is never merged.
    $won = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'status_id' => LeadStatus::query()->forBrand($this->brand)->where('is_won', true)->value('id')]);

    expect(fn () => app(MergeLeads::class)->handle($form, $won, $this->admin))->toThrow(InvalidArgumentException::class);
});

it('only hints at the same person when nothing but the name agrees', function (): void {
    $named = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'John Dingley', 'email' => 'john@example.com']);
    $chat = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'john dingley', 'email' => null, 'phone' => null, 'source_type' => LeadSource::Messenger]);
    // Two people with the same name who both gave an email are two people.
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'John Dingley', 'email' => 'another.john@example.com']);
    $sameEmail = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'J. Dingley', 'email' => 'john@example.com']);

    $twins = collect(LeadMatcher::twins($named, $this->admin))->keyBy(fn (array $twin): int => $twin['lead']->getKey());

    expect($twins->keys()->sort()->values()->all())->toBe(collect([$chat->getKey(), $sameEmail->getKey()])->sort()->values()->all())
        ->and($twins[$sameEmail->getKey()])->toMatchArray(['why' => 'the same email', 'sure' => true])
        ->and($twins[$chat->getKey()])->toMatchArray(['why' => 'the same name', 'sure' => false]);

    Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $chat->getKey()])
        ->assertSee('This may be the same person')
        ->assertSee('the same name');
});
