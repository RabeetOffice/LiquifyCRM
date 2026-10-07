<?php

declare(strict_types=1);

namespace App\Modules\Brands\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Brands\Notifications\InvitationNotification;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Creates a signed invite link for a new Manager or Agent and emails it.
 * An unused invitation for the same email in the same brand is replaced.
 */
final class InviteMember
{
    public function handle(
        Brand $brand,
        string $email,
        BrandRole $role,
        ?Department $department = null,
        ?DepartmentRole $departmentRole = null,
        ?string $name = null,
        ?User $by = null,
    ): Invitation {
        return DB::transaction(function () use ($brand, $email, $role, $department, $departmentRole, $name, $by): Invitation {
            Invitation::query()
                ->acrossBrands()
                ->where('brand_id', $brand->getKey())
                ->where('email', mb_strtolower(trim($email)))
                ->whereNull('accepted_at')
                ->delete();

            $invitation = Invitation::query()->create([
                'brand_id' => $brand->getKey(),
                'department_id' => $department?->getKey(),
                'email' => $email,
                'name' => $name,
                'role' => $role,
                'department_role' => $departmentRole ?? ($department ? DepartmentRole::from($role->value === 'manager' ? 'manager' : 'agent') : null),
                'invited_by' => $by?->getKey(),
            ]);

            Notification::route('mail', $invitation->email)
                ->notify(new InvitationNotification($invitation));

            Audit::log(
                'members.invited',
                $invitation,
                ['email' => $invitation->email, 'role' => $role->value, 'department_id' => $department?->getKey()],
                "Invited {$invitation->email} as {$role->getLabel()}",
                brand: $brand,
                department: $department,
                causer: $by,
                logName: 'members',
            );

            return $invitation;
        });
    }
}
