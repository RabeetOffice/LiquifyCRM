<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Leads\Actions\ArchiveLead;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Exceptions\LeadIsWon;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\AssignableAgents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The "Undo" button on the assign, take, archive and status toasts (10 seconds) puts the
 * previous value back, through the same actions so it is logged too, and
 * under the same rules as doing it by hand: a lead is given only to somebody
 * the assign menu offers, and becomes Won only through a sale. Every screen
 * on the page listens (the list and its slide-over), so each Undo carries a
 * token and runs once.
 */
trait HandlesLeadUndo
{
    use WithToasts;

    #[On('lead-undo')]
    public function undoLeadChange(int $lead, string $kind, ?int $value = null, ?string $token = null): void
    {
        /** @var User $user */
        $user = auth()->user();
        $record = Lead::query()->find($lead);

        if ($record === null || ! $user->can('update', $record)) {
            return;
        }

        // The list and the open slide-over both hear the same Undo: the first one does it.
        if ($token !== null && ! Cache::add('lead-undo:'.$token, true, 120)) {
            return;
        }

        if ($kind === 'assign' && $user->can('assign', $record)) {
            $agent = null;

            if ($value !== null) {
                /** @var Brand $brand */
                $brand = Brand::query()->findOrFail($record->brand_id);
                $agent = app(AssignableAgents::class)->find($user, $brand, $value, $record->department_id);

                if ($agent === null) {
                    return;
                }
            }

            app(AssignLead::class)->handle($record, $agent, $user);
        } elseif ($kind === 'take' && $record->assigned_to === $user->getKey()
            && (Cache::pull(self::takenKey($user, $record)) === true || $user->can('assign', $record))) {
            // Taken by mistake: back to the others, free again. Only a take this person just made, or by
            // whoever may assign the lead: a lead a Manager gave an Agent is not theirs to drop (audit, 28 Oct 2026).
            app(AssignLead::class)->handle($record, null, $user);
        } elseif ($kind === 'archive' && $user->can('archive', $record)) {
            app(ArchiveLead::class)->bringBack($record, $user);
        } elseif ($kind === 'status' && $value !== null) {
            $status = LeadStatus::query()->find($value);

            // Won is only reached by recording the sale.
            if ($status === null || $status->is_won) {
                return;
            }

            try {
                app(ChangeLeadStatus::class)->handle($record, $status, $user);
            } catch (LeadIsWon) {
                // Won with a sale since: it stays there.
                return;
            }
        } else {
            return;
        }

        $this->toastSuccess('Undone');
    }

    /** Remembers for a while that this person took this lead themselves, so their Undo may give it back. */
    protected static function takenKey(User $user, Lead $lead): string
    {
        return 'lead-taken:'.$user->getKey().':'.$lead->getKey();
    }

    /** @return array{event: string, params: array<string, mixed>} */
    protected function undoPayload(Lead $lead, string $kind, ?int $previous): array
    {
        return ['event' => 'lead-undo', 'params' => ['lead' => $lead->getKey(), 'kind' => $kind, 'value' => $previous, 'token' => (string) Str::uuid()]];
    }
}
