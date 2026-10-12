<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\AssignmentMode;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Round robin. When a department shares its leads that way, a lead that
 * lands in it goes straight to the next active Agent in turn; the
 * department remembers whose turn it was. A department that shares by hand,
 * or has no active Agent, leaves the lead in the queue for its Manager.
 */
final class AutoAssignLead
{
    /** One lead: assign it, write the timeline entry and notify the Agent. */
    public function handle(Lead $lead, ?User $by = null): ?User
    {
        if ($lead->assigned_to !== null || $lead->department_id === null) {
            return null;
        }

        return DB::transaction(function () use ($lead, $by): ?User {
            $department = $this->roundRobinDepartment((int) $lead->department_id);
            $agent = $department !== null ? $this->turn($department, $this->agents($department)) : null;

            if ($department === null || $agent === null) {
                return null;
            }

            $department->forceFill(['last_assigned_user_id' => $agent->getKey()])->save();
            app(AssignLead::class)->handle($lead, $agent, $by);

            return $agent;
        });
    }

    /**
     * Many leads at once (a bulk distribution): the same turn order, one
     * update per Agent and no notification per lead.
     *
     * @param  list<int>  $leadIds  oldest first
     * @return array<int, int> Agent id => leads received; empty when the department shares by hand
     */
    public function spread(Department $department, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        return DB::transaction(function () use ($department, $leadIds): array {
            $department = $this->roundRobinDepartment((int) $department->getKey());
            $agents = $department !== null ? $this->agents($department) : new Collection;

            if ($department === null || $agents->isEmpty()) {
                return [];
            }

            $batches = [];

            foreach ($leadIds as $leadId) {
                $agent = $this->turn($department, $agents);

                if ($agent === null) {
                    break;
                }

                $department->last_assigned_user_id = (int) $agent->getKey();
                $batches[(int) $agent->getKey()][] = $leadId;
            }

            foreach ($batches as $agentId => $ids) {
                Lead::query()->acrossBrands()->whereIn('id', $ids)->update(['assigned_to' => $agentId, 'assigned_at' => now(), 'last_activity_at' => now()]);
            }

            $department->save();

            return array_map('count', $batches);
        });
    }

    /**
     * Active Agents of the department, in a fixed order.
     *
     * @return Collection<int, User>
     */
    public function agents(Department $department): Collection
    {
        return $department->agents()
            ->where('users.status', MembershipStatus::Active->value)
            ->whereHas('memberships', fn (Builder $query): Builder => $query
                ->withoutGlobalScopes()
                ->where('brand_id', $department->brand_id)
                ->where('status', MembershipStatus::Active->value))
            ->orderBy('users.id')
            ->get();
    }

    private function roundRobinDepartment(int $departmentId): ?Department
    {
        $department = Department::query()->acrossBrands()->lockForUpdate()->find($departmentId);

        return $department !== null && $department->assignment_mode === AssignmentMode::RoundRobin ? $department : null;
    }

    /**
     * Whoever comes after the Agent that received the last lead.
     *
     * @param  Collection<int, User>  $agents
     */
    private function turn(Department $department, Collection $agents): ?User
    {
        $last = (int) $department->last_assigned_user_id;

        return $agents->first(fn (User $agent): bool => (int) $agent->getKey() > $last) ?? $agents->first();
    }
}
