<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use Illuminate\Auth\Events\Login;

/*
 * 2FA is optional for Managers and Agents and forced for Super Admins and
 * Admins, in both panels.
 */

it('sends an Admin without an authenticator app to the 2FA set-up page in the admin panel', function (): void {
    $admin = User::factory()->admin()->create()->refresh();

    $response = $this->actingAs($admin)->get('/admin/brands');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('multi-factor-authentication');
});

it('sends a Super Admin without 2FA to set-up from the brand panel too', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $super = User::factory()->superAdmin()->create()->refresh();

    $response = $this->actingAs($super)->get('/b/ukph');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('multi-factor-authentication');
});

it('lets an Admin with 2FA in', function (): void {
    $this->actingAs(admin())->get('/admin/brands')->assertOk();
});

it('does not force 2FA on Agents and Managers', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $department = department($brand);

    $this->actingAs(agentOf($department))->get('/b/ukph')->assertOk();

    $this->flushSession();
    $this->actingAs(managerOf($department))->get('/b/ukph')->assertOk();
});

it('records sign-ins in the audit trail', function (): void {
    $agent = agentOf(department(brand(['slug' => 'ukph'])), ['password' => 'Str0ngPassword99']);

    event(new Login('web', $agent, false));

    $this->assertDatabaseHas('activity_log', [
        'event' => 'auth.login',
        'causer_id' => $agent->getKey(),
    ]);

    expect($agent->fresh()->last_login_at)->not->toBeNull();
});

it('can be switched off locally with LIQUIFY_REQUIRE_TWO_FACTOR', function (): void {
    config()->set('liquify.require_two_factor', false);

    $brand = brand(['slug' => 'ukph']);
    $super = User::factory()->superAdmin()->create()->refresh();

    expect($super->requiresTwoFactor())->toBeFalse();

    $this->actingAs($super)->get('/b/ukph')->assertOk();
});
