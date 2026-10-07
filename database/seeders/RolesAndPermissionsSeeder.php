<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission name and the three brand roles as global roles
 * (brand_id NULL), so the same role can be assigned inside any brand through
 * spatie teams. Safe to re-run: it syncs permissions on existing roles.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Roles are global (no team) so they can be used in every brand.
        setPermissionsTeamId(null);

        foreach (Permission::values() as $name) {
            PermissionModel::findOrCreate($name, 'web');
        }

        foreach (BrandRole::cases() as $brandRole) {
            /** @var Role $role */
            $role = Role::query()->firstOrCreate([
                'name' => $brandRole->value,
                'guard_name' => 'web',
                'brand_id' => null,
            ]);

            $role->syncPermissions(array_map(
                fn (Permission $permission): string => $permission->value,
                $brandRole->permissions(),
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
