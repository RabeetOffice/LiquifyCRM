<?php

declare(strict_types=1);

use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Enums\Permission;

/*
 * Layer 3: brand memberships are mirrored into spatie teams, so the
 * permission table is answered per brand.
 */

beforeEach(function (): void {
    $this->brandA = brand(['slug' => 'brand-a']);
    $this->brandB = brand(['slug' => 'brand-b']);
    $this->departmentA = department($this->brandA);
});

it('gives an Agent the Agent permissions inside their brand only', function (): void {
    $agent = agentOf($this->departmentA);

    inBrand($this->brandA);
    expect($agent->hasRole('agent'))->toBeTrue()
        ->and($agent->can(Permission::LeadsView->value))->toBeTrue()
        ->and($agent->can(Permission::LeadsUpdate->value))->toBeTrue()
        ->and($agent->can(Permission::LeadsAssign->value))->toBeFalse()
        ->and($agent->can(Permission::LeadsExport->value))->toBeFalse()
        ->and($agent->can(Permission::UsersManage->value))->toBeFalse();

    inBrand($this->brandB);
    $agent->unsetRelation('roles');
    expect($agent->can(Permission::LeadsView->value))->toBeFalse();
});

it('gives a Manager the Manager permissions', function (): void {
    $manager = managerOf($this->departmentA);

    inBrand($this->brandA);

    expect($manager->can(Permission::LeadsAssign->value))->toBeTrue()
        ->and($manager->can(Permission::LeadsExport->value))->toBeTrue()
        ->and($manager->can(Permission::TargetsSet->value))->toBeTrue()
        ->and($manager->can(Permission::LeadsDistribute->value))->toBeFalse()
        ->and($manager->can(Permission::LeadsDelete->value))->toBeFalse()
        ->and($manager->can(Permission::LeadsImport->value))->toBeFalse()
        ->and($manager->can(Permission::LogsDelete->value))->toBeFalse();
});

it('updates the spatie role when the membership role changes', function (): void {
    $agent = agentOf($this->departmentA);

    app(AddMemberToBrand::class)->handle($this->brandA, $agent, BrandRole::Manager);

    inBrand($this->brandA);
    $agent = $agent->fresh();

    expect($agent->hasRole('manager'))->toBeTrue()
        ->and($agent->hasRole('agent'))->toBeFalse()
        ->and($agent->brandRole($this->brandA))->toBe(BrandRole::Manager);
});

it('removes every role and seat when a member is removed', function (): void {
    $agent = agentOf($this->departmentA);
    $membership = $agent->membershipFor($this->brandA);

    app(RemoveMemberFromBrand::class)->handle($membership);

    inBrand($this->brandA);
    $agent = $agent->fresh();

    expect($agent->membershipFor($this->brandA))->toBeNull()
        ->and($agent->hasRole('agent'))->toBeFalse()
        ->and($agent->departments()->withoutGlobalScopes()->count())->toBe(0)
        ->and($agent->can(Permission::LeadsView->value))->toBeFalse();
});

it('strips permissions while a membership is suspended', function (): void {
    $agent = agentOf($this->departmentA);
    $agent->membershipFor($this->brandA)->forceFill(['status' => MembershipStatus::Suspended])->save();

    inBrand($this->brandA);
    $agent = $agent->fresh();

    expect($agent->can(Permission::LeadsView->value))->toBeFalse();
});

it('resolves global roles through Gate::before', function (): void {
    $super = superAdmin();
    $admin = admin();

    expect($super->can(Permission::SystemManage->value))->toBeTrue()
        ->and($super->can(Permission::LogsDelete->value))->toBeTrue()
        ->and($admin->can(Permission::LeadsDelete->value))->toBeTrue()
        ->and($admin->can(Permission::BrandsManage->value))->toBeTrue()
        ->and($admin->can(Permission::SystemManage->value))->toBeTrue()
        ->and($admin->can(Permission::LogsDelete->value))->toBeTrue();
});

it('denies everything to a suspended global admin', function (): void {
    $admin = admin(['status' => MembershipStatus::Suspended]);

    expect($admin->can(Permission::LeadsView->value))->toBeFalse()
        ->and($admin->canAccessAdminArea())->toBeFalse();
});

it('writes an audit entry when a member is added', function (): void {
    agentOf($this->departmentA);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'members.added',
        'brand_id' => $this->brandA->getKey(),
        'department_id' => $this->departmentA->getKey(),
    ]);
});
