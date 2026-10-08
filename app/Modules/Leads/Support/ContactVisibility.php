<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\Permission;
use App\Modules\Leads\Models\Lead;

/**
 * Who sees full phone and email: Super Admins, Admins and Managers always;
 * an Agent only after clicking Reveal on that lead (kept for the session).
 */
final class ContactVisibility
{
    public const SESSION_KEY = 'leads.revealed';

    public function canSeeFull(User $user, Lead $lead): bool
    {
        if ($user->isGlobalAdmin() || $user->can(Permission::LeadsRevealContact->value)) {
            return true;
        }

        return $this->isRevealed($lead);
    }

    public function isRevealed(Lead $lead): bool
    {
        return in_array((int) $lead->getKey(), $this->revealedIds(), true);
    }

    public function markRevealed(Lead $lead): void
    {
        $ids = $this->revealedIds();
        $ids[] = (int) $lead->getKey();

        session()->put(self::SESSION_KEY, array_values(array_unique($ids)));
    }

    /** @return list<int> */
    private function revealedIds(): array
    {
        return array_map('intval', (array) session()->get(self::SESSION_KEY, []));
    }
}
