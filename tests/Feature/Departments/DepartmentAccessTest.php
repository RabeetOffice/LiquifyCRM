<?php

declare(strict_types=1);

use App\Modules\Departments\Models\Department;
use Illuminate\Database\QueryException;

/*
 * A Manager works inside their own department only.
 */

beforeEach(function (): void {
    $this->brand = brand(['slug' => 'ukph']);
    $this->salesA = department($this->brand, ['name' => 'Sales Team A']);
    $this->consultants = department($this->brand, ['name' => 'Publishing Consultants']);

    $this->managerA = managerOf($this->salesA, ['name' => 'Grace Manager']);
    $this->agentA = agentOf($this->salesA, ['name' => 'Ava SalesA']);
    $this->agentC = agentOf($this->consultants, ['name' => 'Mia Consultant']);
});

it('lets a Manager edit their own department but not another one', function (): void {
    $this->actingAs($this->managerA)
        ->get("/b/ukph/departments/{$this->salesA->getKey()}/edit")
        ->assertOk();

    $this->actingAs($this->managerA)
        ->get("/b/ukph/departments/{$this->consultants->getKey()}/edit")
        ->assertForbidden();
});

it('answers the department policy per department', function (): void {
    inBrand($this->brand);

    expect($this->managerA->can('update', $this->salesA))->toBeTrue()
        ->and($this->managerA->can('update', $this->consultants))->toBeFalse()
        ->and($this->managerA->can('create', Department::class))->toBeFalse()
        ->and($this->managerA->can('delete', $this->salesA))->toBeFalse()
        ->and(admin()->can('create', Department::class))->toBeTrue();
});

it('shows a Manager only the Agents of their own department on the Members screen', function (): void {
    $this->actingAs($this->managerA)
        ->get('/b/ukph/members')
        ->assertOk()
        ->assertSee('Ava SalesA')
        ->assertDontSee('Mia Consultant');
});

it('lets a Manager manage Agents of their department but not of another', function (): void {
    inBrand($this->brand);

    expect($this->managerA->can('update', $this->agentA))->toBeTrue()
        ->and($this->managerA->can('update', $this->agentC))->toBeFalse()
        ->and($this->managerA->managesDepartment($this->salesA))->toBeTrue()
        ->and($this->managerA->managesDepartment($this->consultants))->toBeFalse();
});

it('cannot create a department with a duplicate name inside the same brand', function (): void {
    inBrand($this->brand);

    expect(fn () => Department::query()->create(['name' => 'Sales Team A']))
        ->toThrow(QueryException::class);
});
