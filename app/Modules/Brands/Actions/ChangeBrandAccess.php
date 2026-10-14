<?php

declare(strict_types=1);

namespace App\Modules\Brands\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use Illuminate\Support\Facades\DB;

/**
 * An Admin changes what a person does in one of their brands, from the
 * People screen: the role there (Manager or Agent, each brand on its own) or
 * the department they work in. Their seat in that brand's departments
 * follows the role, so a Manager leads and an Agent works the department.
 * Nothing changes in the person's other brands.
 */
final class ChangeBrandAccess
{
    public function role(Membership $membership, BrandRole $role, User $by): void
    {
        if ($membership->role === $role) {
            return;
        }

        DB::transaction(function () use ($membership, $role, $by): void {
            /** @var User $person */
            $person = $membership->user()->firstOrFail();
            $brand = $this->brand($membership);

            // Saving the membership moves the person's permissions in this brand (spatie team) with it.
            $membership->forceFill(['role' => $role])->save();

            $seats = $this->seatIds($person, $brand);

            if ($seats !== []) {
                $person->departments()->updateExistingPivot($seats, ['role' => self::seatRole($role)->value]);
            }

            Audit::log('members.role_changed', $membership, ['user_id' => $person->getKey(), 'email' => $person->email, 'role' => $role->value], "Changed {$person->name} to {$role->getLabel()}", brand: $brand, causer: $by, logName: 'members');
        });
    }

    /** Move the person to one department of the brand, or out of all of them (null). */
    public function department(Membership $membership, ?Department $department, User $by): void
    {
        DB::transaction(function () use ($membership, $department, $by): void {
            /** @var User $person */
            $person = $membership->user()->firstOrFail();
            $brand = $this->brand($membership);

            abort_if($department !== null && $department->brand_id !== $brand->getKey(), 404);

            $leaving = array_values(array_diff($this->seatIds($person, $brand), $department !== null ? [$department->getKey()] : []));

            if ($leaving !== []) {
                $person->departments()->detach($leaving);
            }

            $department?->addMember($person, self::seatRole($membership->role), $by);

            Audit::log(
                'members.department_changed',
                $membership,
                ['user_id' => $person->getKey(), 'department_id' => $department?->getKey(), 'left' => $leaving],
                $department !== null ? "Moved {$person->name} to {$department->name}" : "Took {$person->name} out of the departments",
                brand: $brand,
                department: $department,
                causer: $by,
                logName: 'members',
            );
        });
    }

    public static function seatRole(BrandRole $role): DepartmentRole
    {
        return $role === BrandRole::Manager ? DepartmentRole::Manager : DepartmentRole::Agent;
    }

    private function brand(Membership $membership): Brand
    {
        /** @var Brand $brand */
        $brand = $membership->brand()->withoutGlobalScopes()->firstOrFail();

        return $brand;
    }

    /** @return list<int> the person's departments in this brand */
    private function seatIds(User $person, Brand $brand): array
    {
        return $person->departmentIds($brand);
    }
}
