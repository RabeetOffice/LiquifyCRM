<?php

declare(strict_types=1);

namespace App\Modules\Leads\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Models\Lead;

/**
 * The Leads rows of the permission table. Super Admin and Admin see and do
 * everything in every brand; a Manager works their own departments; an Agent
 * works only the leads assigned to them. Front sales also sees the rest of
 * its team's leads and takes a free one for itself (Lead::scopeVisibleTo).
 */
final class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && ($user->isGlobalAdmin() || $user->can(Permission::LeadsView->value));
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        if (! $this->inCurrentBrand($lead) || ! $user->isMemberOf($lead->brand_id)) {
            return false;
        }

        return $this->works($user, $lead) || $this->inFrontTeam($user, $lead);
    }

    /**
     * "I am on it": somebody on a front sales team takes a free lead of that
     * team, or one no team has yet. Once it is theirs nobody else can take it;
     * moving it on is for its Manager or an Admin (assign).
     */
    public function take(User $user, Lead $lead): bool
    {
        return $lead->assigned_to === null
            && $this->inCurrentBrand($lead)
            && $user->isMemberOf($lead->brand_id)
            && $this->inFrontTeam($user, $lead)
            && $user->can(Permission::LeadsUpdate->value);
    }

    /** Manual lead: Admin or Manager. */
    public function create(User $user): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        $brand = app(CurrentBrand::class)->get();

        return $brand !== null && $user->isManagerIn($brand);
    }

    /**
     * Status, fields, notes, follow-ups, chat replies and the sale: own
     * department or own leads. A colleague's lead is only looked at.
     */
    public function update(User $user, Lead $lead): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $this->inCurrentBrand($lead)
            && $user->isMemberOf($lead->brand_id)
            && $this->works($user, $lead)
            && $user->can(Permission::LeadsUpdate->value);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->isGlobalAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $user->isGlobalAdmin();
    }

    /** The bin: whoever may delete leads sees what was deleted and brings it back. */
    public function restoreAny(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    /** Delete forever from the bin (user decision, 16 Oct 2026: every Admin, as for delete and restore). */
    public function forceDelete(User $user, Lead $lead): bool
    {
        return $user->isGlobalAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    /** Hand a lead to a department: Admin only. */
    public function distribute(User $user, ?Lead $lead = null): bool
    {
        return $user->isGlobalAdmin();
    }

    /** Assign to an Agent: Admin anywhere, a Manager inside their departments. */
    public function assign(User $user, Lead $lead): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $user->can(Permission::LeadsAssign->value)
            && $user->isManagerIn($lead->brand_id)
            && ($lead->department_id === null || in_array($lead->department_id, $user->managedDepartmentIds($lead->brand_id), true));
    }

    /** "Record a sale (mark Won)": Admins, a Manager in their department, an Agent on their own leads. */
    public function recordSale(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead) && ($user->isGlobalAdmin() || $user->can(Permission::SalesRecord->value));
    }

    /**
     * Whoever works the lead may reveal its contact details (logged, limited
     * for Agents). A colleague's lead, or a free one not taken yet, stays masked.
     */
    public function reveal(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    public function export(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::LeadsExport->value);
    }

    public function import(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    /** The lead is theirs, or in a department they manage. */
    private function works(User $user, Lead $lead): bool
    {
        if ($lead->assigned_to === $user->getKey()) {
            return true;
        }

        return $user->isManagerIn($lead->brand_id)
            && $lead->department_id !== null
            && in_array($lead->department_id, $user->managedDepartmentIds($lead->brand_id), true);
    }

    /** The lead is in one of their front sales teams, or in no team yet while they are on one. */
    private function inFrontTeam(User $user, Lead $lead): bool
    {
        $front = $user->frontDepartmentIds($lead->brand_id);

        return $front !== [] && ($lead->department_id === null || in_array($lead->department_id, $front, true));
    }

    private function inCurrentBrand(Lead $lead): bool
    {
        $brandId = app(CurrentBrand::class)->id();

        return $brandId === null || $brandId === (int) $lead->brand_id;
    }
}
