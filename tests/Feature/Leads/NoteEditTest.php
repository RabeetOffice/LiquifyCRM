<?php

declare(strict_types=1);

use App\Modules\Activity\Models\Activity;
use App\Modules\Leads\Actions\AddLeadNote;
use App\Modules\Leads\Actions\EditLeadNote;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Correcting a note (user request, 4 Nov 2026: agents write a wrong note and
 * need to fix it). The author fixes their own note; an Admin or the lead's
 * Manager fixes anybody's; an Agent never changes a colleague's words. The
 * log keeps what the note said before.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Maria Manager']);
    $this->agent = agentOf($this->front, ['name' => 'Ali Khan']);
    $this->colleague = agentOf($this->front, ['name' => 'Sara Ahmed']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->agent->getKey(),
    ]);
});

it('lets an Agent correct their own note, says it was edited and keeps the old words in the log', function (): void {
    $note = app(AddLeadNote::class)->handle($this->lead, 'Wants 500 copies by Friday', $this->agent);

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('aria-label="Edit this note"', false)
        ->call('saveNote', $note->getKey(), '  Wants 50 copies by Friday  ')
        ->assertReturned(true)
        ->assertDispatched('toast', title: 'Note saved')
        ->assertSee('Wants 50 copies by Friday')
        ->assertSee('· edited')
        ->assertDontSee('Wants 500 copies');

    $note->refresh();
    $log = Activity::query()->where('event', 'leads.note_edited')->sole();

    expect($note->body)->toBe('Wants 50 copies by Friday')
        ->and($note->user_id)->toBe($this->agent->getKey())
        ->and($note->edited_by)->toBe($this->agent->getKey())
        ->and($note->edited_at)->not->toBeNull()
        ->and($log->subject_id)->toBe($this->lead->getKey())
        ->and($log->causer_id)->toBe($this->agent->getKey())
        ->and($log->properties['before'])->toBe('Wants 500 copies by Friday')
        ->and($log->properties['excerpt'])->toBe('Wants 50 copies by Friday');
});

it("never lets an Agent change a colleague's note", function (): void {
    $note = app(AddLeadNote::class)->handle($this->lead, 'Called, no answer', $this->agent);

    // A colleague on the same team sees the lead but cannot work it, let alone change the note.
    Livewire::actingAs($this->colleague)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertDontSee('aria-label="Edit this note"', false)
        ->call('saveNote', $note->getKey(), 'Changed by someone else')
        ->assertForbidden();

    // Nor on a lead they both work: an Admin's note on the Agent's own lead stays the Admin's.
    $adminNote = app(AddLeadNote::class)->handle($this->lead, 'Quote sent by email', $this->admin);

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('saveNote', $adminNote->getKey(), 'Changed')
        ->assertForbidden();

    expect($note->fresh()->body)->toBe('Called, no answer')
        ->and($adminNote->fresh()->body)->toBe('Quote sent by email')
        ->and(Activity::query()->where('event', 'leads.note_edited')->exists())->toBeFalse();
});

it("lets the lead's Manager and an Admin correct an Agent's note, and names who edited it", function (): void {
    $note = app(AddLeadNote::class)->handle($this->lead, 'Budget is 2000', $this->agent);

    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('aria-label="Edit this note"', false)
        ->call('saveNote', $note->getKey(), 'Budget is 1200')
        ->assertReturned(true)
        ->assertSee('edited by Maria Manager');

    Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('saveNote', $note->getKey(), 'Budget is 1500')
        ->assertReturned(true);

    expect($note->fresh()->body)->toBe('Budget is 1500')
        ->and($note->fresh()->user_id)->toBe($this->agent->getKey())
        ->and($note->fresh()->edited_by)->toBe($this->admin->getKey())
        ->and(Activity::query()->where('event', 'leads.note_edited')->count())->toBe(2);
});

it('refuses an empty note and a note of another lead', function (): void {
    $note = app(AddLeadNote::class)->handle($this->lead, 'Sent the price list', $this->agent);
    $other = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $otherNote = app(AddLeadNote::class)->handle($other, 'Another lead', $this->agent);

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('saveNote', $note->getKey(), '   ')
        ->assertHasErrors(['noteEdit' => 'required'])
        ->assertNotDispatched('toast')
        ->call('saveNote', $otherNote->getKey(), 'Moved here')
        ->assertNotFound();

    expect($note->fresh()->body)->toBe('Sent the price list')
        ->and($note->fresh()->edited_at)->toBeNull()
        ->and($otherNote->fresh()->body)->toBe('Another lead');
});

it('shows the corrected words on the timeline and logs nothing when the note did not change', function (): void {
    $note = app(AddLeadNote::class)->handle($this->lead, 'Wrong client name: Jhon', $this->agent);

    app(EditLeadNote::class)->handle($note, 'Wrong client name: Jhon', $this->agent);
    expect(Activity::query()->where('event', 'leads.note_edited')->exists())->toBeFalse()
        ->and($note->fresh()->edited_at)->toBeNull();

    app(EditLeadNote::class)->handle($note, 'Client name: John', $this->agent);

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->set('tab', 'timeline')
        ->assertSee('Note edited')
        ->assertSee('Client name: John')
        ->assertDontSee('Jhon');
});
