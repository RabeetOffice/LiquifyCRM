<?php

declare(strict_types=1);

use App\Modules\Leads\Models\Lead;

/*
 * The two dashboards: admin overview and brand dashboard with the setup
 * checklist, the New / Unassigned queue, cards and charts, by role.
 */

it('shows the admin overview with cards and the add brand button', function (): void {
    brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);

    $this->actingAs(superAdmin())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Here is the summary across all brands.')
        ->assertSee('Active brands')
        ->assertSee('Manage brands')
        ->assertSee('Add brand')
        ->assertSee('LiquifyCRM version')
        ->assertSee('An update is ready to apply');
});

it('keeps Managers and Agents out of the admin area', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $department = department($brand);

    $this->actingAs(managerOf($department))->get('/admin')->assertForbidden();
    $this->flushSession();
    $this->actingAs(agentOf($department))->get('/admin/brands')->assertForbidden();
});

it('shows the brand dashboard with the setup checklist and the queue to a global admin', function (): void {
    $brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $department = department($brand, ['name' => 'Sales Team A']);
    managerOf($department);
    Lead::factory()->create(['brand_id' => $brand->getKey(), 'name' => 'Sarah Lee']);

    $this->actingAs(superAdmin())
        ->get('/b/ukph')
        ->assertOk()
        ->assertSee('Here is the summary for UK Publishing House.')
        ->assertSee('New / Unassigned')
        ->assertSee('Sarah Lee')
        ->assertSee('New leads today')
        ->assertSee('Leads per day');
});

it('gives an Agent their own numbers and follow-ups instead of the queue', function (): void {
    $brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $department = department($brand, ['name' => 'Sales Team A']);
    $agent = agentOf($department);
    Lead::factory()->create(['brand_id' => $brand->getKey(), 'name' => 'Queued Lead']);

    $this->actingAs($agent)
        ->get('/b/ukph')
        ->assertOk()
        ->assertSee('My open leads')
        ->assertSee('Follow-ups due today')
        ->assertDontSee('Set up UK Publishing House')
        ->assertDontSee('Queued Lead');
});
