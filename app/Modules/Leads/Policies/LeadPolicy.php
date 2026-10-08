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
 * works only the leads assigned to them.
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

        if ($lead->assigned_to === $user->getKey()) {
            return true;
        }

        return $user->isManagerIn($lead->brand_id)
            && $lead->department_id !== null
            && in_array($lead->department_id, $user->managedDepartmentIds($lead->brand_id), true);
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

    /** Status, fields, notes and follow-ups: own department or own leads. */
    public function update(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead) && ($user->isGlobalAdmin() || $user->can(Permission::LeadsUpdate->value));
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

    public function forceDelete(User $user, Lead $lead): bool
    {
        return $user->isSuperAdmin();
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

    /** Anyone who may see the lead may reveal its contact details (logged, limited for Agents). */
    public function reveal(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead);
    }

    public function export(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::LeadsExport->value);
    }

    public function import(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    private function inCurrentBrand(Lead $lead): bool
    {
        $brandId = app(CurrentBrand::class)->id();

        return $brandId === null || $brandId === (int) $lead->brand_id;
    }
}
