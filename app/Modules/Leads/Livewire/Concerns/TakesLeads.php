<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Leads\Actions\RevealLeadContact;
use App\Modules\Leads\Actions\TakeLead;
use App\Modules\Leads\Exceptions\LeadAlreadyTaken;
use App\Modules\Leads\Exceptions\RevealLimitReached;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\ContactVisibility;
use Illuminate\Support\Facades\Cache;

/**
 * The Take button of front sales, wherever a free lead shows: one click and
 * the lead is theirs. When somebody else was quicker the person is told who,
 * not shown an error. Taking opens the phone and email at once (a reveal, so
 * it is logged and counts towards the hourly limit), and the toast's Undo
 * puts the lead back for the others.
 */
trait TakesLeads
{
    use HandlesLeadUndo;

    public function takeLead(int $leadId): void
    {
        /** @var User $user */
        $user = auth()->user();
        $lead = Lead::query()->visibleTo($user)->with('assignee')->findOrFail($leadId);

        if ($lead->assigned_to !== null) {
            $lead->assigned_to === $user->getKey()
                ? $this->toastSuccess('This lead is already yours')
                : $this->toastWarning('Already taken', ($lead->assignee->name ?? 'Somebody').' is on this lead.');

            return;
        }

        abort_unless($user->can('take', $lead), 403);

        try {
            app(TakeLead::class)->handle($lead, $user);
        } catch (LeadAlreadyTaken $exception) {
            $this->toastWarning('Already taken', $exception->getMessage());

            return;
        }

        $lead->refresh();
        Cache::put(self::takenKey($user, $lead), true, now()->addMinutes(10));
        $note = 'It is yours now: nobody else can take it.';

        if (! app(ContactVisibility::class)->canSeeFull($user, $lead)) {
            try {
                app(RevealLeadContact::class)->handle($lead, $user);
                $note = 'It is yours now, and its phone and email are open.';
            } catch (RevealLimitReached $exception) {
                $note = 'It is yours now. '.$exception->getMessage();
            }
        }

        $this->dispatch('lead-updated', id: $lead->getKey());
        $this->toast('You took '.$lead->name, $note, 'success', $this->undoPayload($lead, 'take', null));
    }
}
