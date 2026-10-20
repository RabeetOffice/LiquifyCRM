<?php

declare(strict_types=1);

use App\Modules\Brands\Support\BrandClocks;

/*
 * Clocks in the top bar (user request, 20 Oct 2026): the person's own time,
 * the brand's, and the brand time zones they may add.
 */

beforeEach(function (): void {
    $this->uk = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'timezone' => 'Europe/London']);
    $this->anz = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz', 'timezone' => 'Australia/Sydney']);
    $this->ireland = brand(['name' => 'Ireland Publishing House', 'slug' => 'ie', 'timezone' => 'Europe/Dublin']);
    brand(['name' => 'Closed Brand', 'slug' => 'closed', 'timezone' => 'America/New_York', 'is_active' => false]);
    brand(['name' => 'Second London Brand', 'slug' => 'ldn2', 'timezone' => 'Europe/London']);
});

it('offers one clock per brand time zone, naming only the brands the person works in', function (): void {
    $choices = collect(BrandClocks::choices(admin()))->keyBy('zone');

    expect($choices->keys()->all())->toBe(['Europe/Dublin', 'Europe/London', 'Australia/Sydney'])
        ->and($choices['Europe/London'])->toBe(['zone' => 'Europe/London', 'city' => 'London', 'brands' => 'Second London Brand, UK Publishing House']);

    inBrand($this->uk);
    $manager = managerOf(department($this->uk));
    $choices = collect(BrandClocks::choices($manager))->keyBy('zone');

    expect($choices['Europe/London']['brands'])->toBe('UK Publishing House')
        ->and($choices['Australia/Sydney']['brands'])->toBe('');
});

it('shows the clocks in the top bar with the brand\'s own time zone', function (): void {
    inBrand($this->anz);
    $manager = managerOf(department($this->anz));

    $this->actingAs($manager)
        ->get('/b/anz')
        ->assertOk()
        ->assertSee('x-data="clocks(', false)
        ->assertSee('Sydney', false)
        ->assertSee('ANZ Publishing House');
});
