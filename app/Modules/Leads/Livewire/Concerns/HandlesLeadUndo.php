<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use Livewire\Attributes\On;

/**
 * The "Undo" button on the assign and status toasts (10 seconds) puts the
 * previous value back, through the same actions so it is logged too.
 */
trait HandlesLeadUndo
{
    use WithToasts;

    #[On('lead-undo')]
    public function undoLeadChange(int $lead, string $kind, ?int $value = null): void
    {
        /** @var User $user */
        $user = auth()->user();
        $record = Lead::query()->find($lead);

        if ($record === null || ! $user->can('update', $record)) {
            return;
        }

        if ($kind === 'assign' && $user->can('assign', $record)) {
            app(AssignLead::class)->handle($record, $value ? User::query()->find($value) : null, $user);
        } elseif ($kind === 'status' && $value !== null) {
            $status = LeadStatus::query()->find($value);

            if ($status === null) {
                return;
            }

            app(ChangeLeadStatus::class)->handle($record, $status, $user);
        } else {
            return;
        }

        $this->toastSuccess('Undone');
    }

    /** @return array{event: string, params: array<string, mixed>} */
    protected function undoPayload(Lead $lead, string $kind, ?int $previous): array
    {
        return ['event' => 'lead-undo', 'params' => ['lead' => $lead->getKey(), 'kind' => $kind, 'value' => $previous]];
    }
}
