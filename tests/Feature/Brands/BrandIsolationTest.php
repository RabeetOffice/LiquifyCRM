<?php

declare(strict_types=1);

use App\Modules\Core\Enums\BrandRole;
use App\Modules\Departments\Models\Department;

/*
 * Brand isolation, layer by layer. An Agent (or Manager) of Brand A must get
 * a 404 on every Brand B URL, exactly as the Security section demands.
 */

beforeEach(function (): void {
    $this->brandA = brand(['name' => 'Brand A', 'slug' => 'brand-a']);
    $this->brandB = brand(['name' => 'Brand B', 'slug' => 'brand-b']);
    $this->departmentA = department($this->brandA, ['name' => 'Sales A']);
    $this->departmentB = department($this->brandB, ['name' => 'Sales B']);
    $this->agentA = agentOf($this->departmentA);
    $this->managerA = managerOf($this->departmentA);
});

it('lets an Agent open their own brand', function (): void {
    $this->actingAs($this->agentA)
        ->get('/b/brand-a')
        ->assertOk();
});

it('returns 404 on every Brand B URL for an Agent of Brand A', function (string $path): void {
    $url = str_replace(['{dept}', '{member}'], [
        (string) $this->departmentB->getKey(),
        (string) $this->brandB->memberships()->withoutGlobalScopes()->value('id'),
    ], $path);

    $this->actingAs($this->agentA)
        ->get('/b/brand-b'.$url)
        ->assertNotFound();
})->with([
    'dashboard' => '',
    'departments' => '/departments',
    'department create' => '/departments/create',
    'department edit' => '/departments/{dept}/edit',
    'members' => '/members',
    'members create' => '/members/create',
]);

it('returns 404 on Brand B for a Manager of Brand A', function (): void {
    $this->actingAs($this->managerA)
        ->get('/b/brand-b')
        ->assertNotFound();

    $this->actingAs($this->managerA)
        ->get('/b/brand-b/departments')
        ->assertNotFound();
});

it('does not list Brand B in the brand switcher for a member of Brand A', function (): void {
    expect($this->agentA->accessibleBrands()->pluck('slug')->all())->toBe(['brand-a'])
        ->and($this->agentA->canAccessBrand($this->brandB))->toBeFalse()
        ->and($this->agentA->canAccessBrand($this->brandA))->toBeTrue();
});

it('lets a global Admin open every brand', function (): void {
    $admin = admin();

    $this->actingAs($admin)->get('/b/brand-a')->assertOk();
    $this->actingAs($admin)->get('/b/brand-b')->assertOk();
});

it('hides inactive brands even from their members', function (): void {
    $this->brandA->forceFill(['is_active' => false])->save();

    $this->actingAs($this->agentA)
        ->get('/b/brand-a')
        ->assertNotFound();
});

it('keeps an Agent out of the Departments and Members screens of their own brand', function (): void {
    $this->actingAs($this->agentA)->get('/b/brand-a/departments')->assertForbidden();
    $this->actingAs($this->agentA)->get('/b/brand-a/members')->assertForbidden();
});

it('scopes brand-owned queries to the current brand', function (): void {
    inBrand($this->brandA);

    expect(Department::query()->pluck('name')->all())->toBe(['Sales A']);

    inBrand($this->brandB);

    expect(Department::query()->pluck('name')->all())->toBe(['Sales B']);

    inBrand(null);

    expect(Department::query()->count())->toBe(2);
});

it('assigns brand_id automatically from the current brand', function (): void {
    inBrand($this->brandB);

    $department = Department::query()->create(['name' => 'Auto']);

    expect($department->brand_id)->toBe($this->brandB->getKey());
});

it('blocks a suspended member from the brand', function (): void {
    $membership = $this->agentA->membershipFor($this->brandA);
    $membership->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($this->agentA->fresh())
        ->get('/b/brand-a')
        ->assertForbidden();
});

it('gives a Viewer no editing permissions', function (): void {
    $viewer = memberOf($this->brandA, BrandRole::Viewer);
    inBrand($this->brandA);

    expect($viewer->can('sales.view'))->toBeTrue()
        ->and($viewer->can('leads.update'))->toBeFalse();
});
