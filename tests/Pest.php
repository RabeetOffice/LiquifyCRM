<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Departments\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CurrentBrand::class)->clear();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** A Super Admin with an authenticator app already set up. */
function superAdmin(array $attributes = []): User
{
    return User::factory()->superAdmin()->withTwoFactor()->create($attributes)->refresh();
}

/** An Admin with an authenticator app already set up. */
function admin(array $attributes = []): User
{
    return User::factory()->admin()->withTwoFactor()->create($attributes)->refresh();
}

function brand(array $attributes = []): Brand
{
    return Brand::factory()->create($attributes);
}

function department(Brand $brand, array $attributes = []): Department
{
    return Department::factory()->create(['brand_id' => $brand->getKey(), ...$attributes]);
}

/**
 * A Manager or Agent of a brand, optionally seated in a department.
 */
function memberOf(Brand $brand, BrandRole $role, ?Department $department = null, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    app(AddMemberToBrand::class)->handle(
        $brand,
        $user,
        $role,
        $department,
        $department ? DepartmentRole::from($role === BrandRole::Manager ? 'manager' : 'agent') : null,
    );

    return $user->fresh();
}

function managerOf(Department $department, array $attributes = []): User
{
    return memberOf($department->brand()->withoutGlobalScopes()->firstOrFail(), BrandRole::Manager, $department, $attributes);
}

function agentOf(Department $department, array $attributes = []): User
{
    return memberOf($department->brand()->withoutGlobalScopes()->firstOrFail(), BrandRole::Agent, $department, $attributes);
}

/** Point the brand scope and permission team at a brand, as the panel middleware does. */
function inBrand(?Brand $brand): void
{
    app(CurrentBrand::class)->set($brand);
}
