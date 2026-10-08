<?php

declare(strict_types=1);

/*
 * The two dashboards render the Liquify cards: a hero stat, the footer
 * links, the setup checklist with its artwork, and one primary action.
 */

it('shows the admin overview with the hero card and the add brand button', function (): void {
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    brand(['name' => 'UK Publishing', 'slug' => 'ukph']);

    $this->actingAs(superAdmin())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Here is the summary across all brands.')
        ->assertSee('lq-stat-hero')
        ->assertSee('Manage brands')
        ->assertSee('Add brand')
        ->assertSee('Database is up to date')
        ->assertSee('Scheduler quiet');
});

it('shows the brand dashboard with the setup checklist for a global admin', function (): void {
    $brand = brand(['name' => 'UK Publishing', 'slug' => 'ukph']);
    department($brand, ['name' => 'Sales Team A']);

    $this->actingAs(superAdmin())
        ->get('/b/ukph')
        ->assertOk()
        ->assertSee('Here is the summary for UK Publishing.')
        ->assertSee('Set up UK Publishing')
        ->assertSee('onboarding-welcome.webp')
        ->assertSee('lq-stat-hero')
        ->assertSee('Manage team')
        ->assertSee('Invite by email');
});

it('gives a Manager the team cards and an Agent only their role', function (): void {
    $brand = brand(['name' => 'UK Publishing', 'slug' => 'ukph']);
    $department = department($brand, ['name' => 'Sales Team A']);

    $this->actingAs(managerOf($department))
        ->get('/b/ukph')
        ->assertOk()
        ->assertSee('Manage team')
        ->assertSee('View departments')
        ->assertDontSee('Set up UK Publishing');

    $this->flushSession();

    $this->actingAs(agentOf($department))
        ->get('/b/ukph')
        ->assertOk()
        ->assertSee('Your role here')
        ->assertSee('In UK Publishing')
        ->assertDontSee('Manage team')
        ->assertDontSee('Invite by email');
});
