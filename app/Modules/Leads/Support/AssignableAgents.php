<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Departments\Models\Department;
use Illuminate\Database\Eloquent\Builder;

/**
 * The active Agents of a brand a person may hand leads to: Admins all of
 * them, a Manager those in the departments they lead.
 */
final class AssignableAgents
{
    /** @return array<int, string> */
    public function for(User $user, Brand $brand): array
    {
        // The same people as the assign menu: somebody who only works clients is not given leads.
        return collect($this->people($user, $brand))->pluck('name', 'id')->all();
    }

    /**
     * The same people, with what the assign menu shows of each: the picture,
     * and the departments they work in (a lead that is in a department is
     * only offered that department's people). Somebody whose every department
     * looks after clients (Upsell, project managers) has no lead screens, so
     * new leads are not offered to them.
     *
     * @return list<array{id: int, name: string, picture: ?string, team: string, departments: list<int>}>
     */
    public function people(User $user, Brand $brand): array
    {
        $departments = $this->departmentsByPerson($brand);
        $people = [];

        foreach ($this->query($user, $brand)->get() as $agent) {
            $own = $departments[(int) $agent->getKey()] ?? [];

            if ($this->worksClientsOnly($own)) {
                continue;
            }

            $people[] = [
                'id' => (int) $agent->getKey(),
                'name' => $agent->name,
                'picture' => $agent->avatarUrl(),
                'team' => implode(', ', array_map(fn (Department $department): string => $department->name, $own)),
                'departments' => array_map(fn (Department $department): int => (int) $department->getKey(), $own),
            ];
        }

        return $people;
    }

    /**
     * One of those people by id, for a lead in `$departmentId` (null: not in
     * a department yet). Null when the person may not be given that lead.
     */
    public function find(User $user, Brand $brand, int $agentId, ?int $departmentId): ?User
    {
        $agent = $this->query($user, $brand)->whereKey($agentId)->first();

        if ($agent === null) {
            return null;
        }

        $own = $this->departmentsByPerson($brand)[(int) $agent->getKey()] ?? [];
        $inDepartment = $departmentId === null || in_array($departmentId, array_map(fn (Department $department): int => (int) $department->getKey(), $own), true);

        return $inDepartment && ! $this->worksClientsOnly($own) ? $agent : null;
    }

    /**
     * The brand's departments each person works in, by the person's id, in the order of their names.
     *
     * @return array<int, list<Department>>
     */
    private function departmentsByPerson(Brand $brand): array
    {
        $map = [];

        foreach (Department::query()->forBrand($brand)->with('users')->orderBy('name')->get() as $department) {
            foreach ($department->users as $member) {
                $map[(int) $member->getKey()][] = $department;
            }
        }

        return $map;
    }

    /** @param  list<Department>  $departments */
    private function worksClientsOnly(array $departments): bool
    {
        return $departments !== [] && array_filter($departments, fn (Department $department): bool => ! $department->isUpsell()) === [];
    }

    /** @return Builder<User> */
    private function query(User $user, Brand $brand): Builder
    {
        $query = User::query()
            ->whereHas('memberships', fn (Builder $query): Builder => $query->acrossBrands()->where('brand_id', $brand->getKey())->where('role', BrandRole::Agent->value)->where('status', 'active'))
            ->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereHas('departments', fn (Builder $query): Builder => $query->acrossBrands()->whereIn('departments.id', $user->managedDepartmentIds($brand)));
        }

        return $query;
    }
}
