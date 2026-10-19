<?php

declare(strict_types=1);

use App\Modules\Core\Livewire\BrandDashboard;
use App\Modules\Leads\Actions\ArchiveLead;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Actions\MergeLeads;
use App\Modules\Leads\Actions\ScheduleFollowUp;
use App\Modules\Leads\Livewire\App\FollowUps;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadEnquiry;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Two requests of 19 Oct 2026: one click copies a lead's name, phone, email
 * and what they asked for; a Manager archives a lead that leads nowhere, out
 * of the working lists, and it comes back with one click or by itself when
 * the person gets in touch again.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Maria Manager']);
    $this->agent = agentOf($this->front, ['name' => 'Ali Khan']);
    $this->admin = admin();

    inBrand($this->brand);
});

it('copies the name, phone, email and what they asked for in one piece, masked where the viewer sees it masked', function (): void {
    $lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(),
        'name' => 'Martin Hesketh', 'phone' => '+447545390156', 'email' => 'rm@example.com', 'source_type' => 'facebook',
        'message' => null,
        'fields' => ['what_genre_category_best_describes_your_book' => 'fiction', 'service' => 'Publishing', 'facebook_page' => 'UKPH Page'],
    ]);

    expect(LeadEnquiry::copyText($lead, true))->toBe(implode("\n", [
        'Martin Hesketh',
        'Phone: +447545390156',
        'Email: rm@example.com',
        'Source: Facebook',
        '',
        'What they asked for',
        'Service: Publishing',
        'What genre category best describes your book? fiction',
    ]))
        ->and(LeadEnquiry::copyText($lead, false))->toContain('Phone: +44 75•• •••156')->not->toContain('rm@example.com')
        // Where the lead came from is not what they asked for.
        ->and(LeadEnquiry::copyText($lead, true))->not->toContain('UKPH Page');

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('Copy name, phone, email and what they asked for')
        ->assertSee('Email: rm@example.com', false);

    // The Agent sees the contact masked until they reveal it, and so does what they copy.
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertDontSee('rm@example.com', false);
});

it('lets a Manager archive a lead out of every working list, and bring it back', function (): void {
    $junk = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'name' => 'Spam Lead']);
    $page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token']);
    Conversation::query()->create(['brand_id' => $this->brand->getKey(), 'lead_id' => $junk->getKey(), 'contact_external_id' => '777', 'contact_name' => 'Spam Lead', 'meta_source_id' => $page->getKey(), 'last_message_at' => now(), 'last_message_preview' => 'Buy followers now']);

    expect($this->manager->can('archive', $junk))->toBeTrue()
        ->and($this->agent->can('archive', $junk))->toBeFalse();

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $junk->getKey()])
        ->assertSee('Archive')
        ->call('archive')
        ->assertDispatched('toast', title: 'Spam Lead archived')
        ->assertSee('Archived by Maria Manager')
        ->assertSee('Bring back');

    $junk->refresh();

    expect($junk->isArchived())->toBeTrue()
        ->and($junk->archived_by)->toBe($this->manager->getKey())
        ->and($junk->activities()->where('event', 'leads.archived')->count())->toBe(1)
        ->and(Lead::query()->unassigned()->count())->toBe(0);

    // Out of the list, the queue, the pipeline and the Inbox, for the Manager and for front sales.
    Livewire::actingAs($this->manager)->test(LeadsIndex::class, ['brand' => $this->brand])->assertDontSee('Spam Lead')
        ->set('tab', 'archived')->assertSee('Spam Lead');
    Livewire::actingAs($this->agent)->test(LeadsIndex::class, ['brand' => $this->brand])->assertDontSee('Spam Lead')->assertDontSee('Archived')
        ->set('tab', 'archived')->assertDontSee('Spam Lead');
    Livewire::actingAs($this->agent)->test(BrandDashboard::class, ['brand' => $this->brand])->assertDontSee('Spam Lead');
    Livewire::actingAs($this->manager)->test(Pipeline::class, ['brand' => $this->brand])->assertDontSee('Spam Lead');
    Livewire::actingAs($this->manager)->test(Inbox::class, ['brand' => $this->brand])->assertDontSee('Buy followers now');

    // Nobody takes an archived lead.
    expect($this->agent->can('take', $junk))->toBeFalse();

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $junk->getKey()])
        ->call('bringBack')
        ->assertDontSee('Archived by');

    expect($junk->fresh()->isArchived())->toBeFalse()
        ->and($junk->activities()->where('event', 'leads.unarchived')->count())->toBe(1);
});

it('undoes an archive from its message', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey()]);

    Livewire::actingAs($this->manager)->test(LeadPanel::class, ['leadId' => $lead->getKey()])->call('archive');
    expect($lead->fresh()->isArchived())->toBeTrue();

    Livewire::actingAs($this->manager)->test(LeadsIndex::class, ['brand' => $this->brand])->call('undoLeadChange', $lead->getKey(), 'archive', null, 'token-1');
    expect($lead->fresh()->isArchived())->toBeFalse();
});

it('keeps Agents and other departments\' Managers from archiving', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $otherManager = managerOf(department($this->brand, ['name' => 'Publishing']));

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertDontSee('wire:click="archive"', false)
        ->call('archive')
        ->assertForbidden();

    expect($otherManager->can('archive', $lead))->toBeFalse()
        ->and($this->admin->can('archive', $lead))->toBeTrue()
        ->and($lead->fresh()->isArchived())->toBeFalse();
});

it('archives and brings back the ticked leads, only those of the Manager\'s departments', function (): void {
    $mine = Lead::factory()->count(2)->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey()]);
    $theirs = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => department($this->brand, ['name' => 'Publishing'])->getKey()]);

    Livewire::actingAs($this->admin)->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('selected', [...$mine->modelKeys(), $theirs->getKey()])
        ->call('bulkArchive')
        ->assertSet('selected', []);

    expect(Lead::query()->archived()->count())->toBe(3);

    // The Manager brings back what is theirs; the other team's lead stays archived.
    Livewire::actingAs($this->manager)->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('tab', 'archived')
        ->set('selected', [...$mine->modelKeys(), $theirs->getKey()])
        ->call('bulkBringBack')
        ->assertDispatched('toast', title: '2 leads back in the lists');

    expect(Lead::query()->archived()->pluck('id')->all())->toBe([$theirs->getKey()]);
});

it('brings an archived lead back when the person gets in touch again, not for a quiet import', function (): void {
    $lead = app(CreateLead::class)->handle($this->brand, ['name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'department_id' => $this->front->getKey()])->lead;
    app(ArchiveLead::class)->handle($lead, $this->manager);

    // An old list imported quietly does not wake it up.
    app(CreateLead::class)->handle($this->brand, ['name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'silent' => true]);
    expect($lead->fresh()->isArchived())->toBeTrue();

    // A new enquiry does, with a line on its timeline.
    $again = app(CreateLead::class)->handle($this->brand, ['name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'message' => 'Still interested']);

    expect($again->wasMerged)->toBeTrue()
        ->and($lead->fresh()->isArchived())->toBeFalse()
        ->and($lead->activities()->where('event', 'leads.unarchived')->value('description'))->toBe('Back from the archive: they got in touch again');

    // So does a Facebook form that joins the lead.
    app(ArchiveLead::class)->handle($lead->fresh(), $this->manager);
    app(MergeLeads::class)->join($lead->fresh(), ['name' => 'Sarah Lee', 'message' => 'Form again'], 'leads.form_joined', 'Filled in the form');

    expect($lead->fresh()->isArchived())->toBeFalse();
});

it('leaves the follow-ups of an archived lead off the lists', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey()]);
    app(ScheduleFollowUp::class)->handle($lead, now(), 'Call the spammer', $this->agent);

    Livewire::actingAs($this->agent)->test(FollowUps::class, ['brand' => $this->brand])->assertSee('Call the spammer');

    app(ArchiveLead::class)->handle($lead, $this->manager);

    Livewire::actingAs($this->agent)->test(FollowUps::class, ['brand' => $this->brand])->assertDontSee('Call the spammer');
    Livewire::actingAs($this->agent)->test(LeadsIndex::class, ['brand' => $this->brand])->set('tab', 'mine')->assertDontSee($lead->name);
});
