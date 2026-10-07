<?php

declare(strict_types=1);

use App\Modules\Auth\Exceptions\ProtectedUserException;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;

/*
 * Super Admin protection: nobody else can remove, demote, suspend or edit a
 * Super Admin; only a Super Admin can create another; the last one can never
 * be removed.
 */

it('marks every Super Admin as protected', function (): void {
    $super = superAdmin(['is_protected' => false]);

    expect($super->fresh()->is_protected)->toBeTrue();
});

it('does not let an Admin edit, suspend or delete a Super Admin', function (): void {
    $super = superAdmin();
    $admin = admin();

    expect($admin->can('update', $super))->toBeFalse()
        ->and($admin->can('suspend', $super))->toBeFalse()
        ->and($admin->can('delete', $super))->toBeFalse()
        ->and($admin->can('view', $super))->toBeTrue();
});

it('does not let another Super Admin edit, suspend or delete a Super Admin', function (): void {
    $super = superAdmin();
    $other = superAdmin();

    expect($other->can('update', $super))->toBeFalse()
        ->and($other->can('suspend', $super))->toBeFalse()
        ->and($other->can('delete', $super))->toBeFalse()
        ->and($super->can('update', $super))->toBeTrue();
});

it('only lets a Super Admin hand out global roles', function (): void {
    $super = superAdmin();
    $admin = admin();

    expect($super->can('assignGlobalRole', [User::class, GlobalRole::SuperAdmin]))->toBeTrue()
        ->and($super->can('assignGlobalRole', [User::class, GlobalRole::Admin]))->toBeTrue()
        ->and($admin->can('assignGlobalRole', [User::class, GlobalRole::Admin]))->toBeFalse()
        ->and($admin->can('assignGlobalRole', [User::class, GlobalRole::SuperAdmin]))->toBeFalse()
        ->and($admin->can('assignGlobalRole', [User::class, null]))->toBeTrue();
});

it('never deletes the last Super Admin', function (): void {
    $super = superAdmin();

    expect(fn () => $super->delete())->toThrow(ProtectedUserException::class)
        ->and(User::superAdmins()->count())->toBe(1);
});

it('never demotes the last Super Admin', function (): void {
    $super = superAdmin();

    expect(function () use ($super): void {
        $super->role = GlobalRole::Admin;
        $super->save();
    })->toThrow(ProtectedUserException::class);

    expect($super->fresh()->role)->toBe(GlobalRole::SuperAdmin);
});

it('allows demoting a Super Admin when another one remains', function (): void {
    $first = superAdmin();
    $second = superAdmin();

    $second->role = GlobalRole::Admin;
    $second->save();

    expect(User::superAdmins()->count())->toBe(1)
        ->and($first->fresh()->isSuperAdmin())->toBeTrue();
});

it('keeps the admin accounts screen for Super Admins only', function (): void {
    $this->actingAs(admin())->get('/admin/admins')->assertForbidden();

    $this->flushSession();
    $this->actingAs(superAdmin())->get('/admin/admins')->assertOk();
});

it('keeps Managers and Agents out of the admin panel', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $dept = department($brand);

    $this->actingAs(managerOf($dept))->get('/admin')->assertForbidden();

    $this->flushSession();
    $this->actingAs(agentOf($dept))->get('/admin/brands')->assertForbidden();
});

it('lets an Admin manage Managers and Agents but not other Admins', function (): void {
    $brand = brand();
    $dept = department($brand);
    $admin = admin();
    $otherAdmin = admin();
    $manager = managerOf($dept);

    inBrand($brand);

    expect($admin->can('update', $manager))->toBeTrue()
        ->and($admin->can('delete', $manager))->toBeTrue()
        ->and($admin->can('update', $otherAdmin))->toBeFalse()
        ->and($admin->can('delete', $otherAdmin))->toBeFalse()
        ->and($admin->can('delete', $admin))->toBeFalse();
});
