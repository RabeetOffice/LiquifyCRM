<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\MembershipStatus;

/*
 * Sign-in through Laravel Fortify with LiquifyCRM's landing rules.
 */

it('shows the sign-in page with the brand artwork', function (): void {
    $this->get('/login')->assertOk()->assertSee('Sign in')->assertSee('Forgot password?');
});

it('lands a brand member in their brand after signing in', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $agent = agentOf(department($brand), ['password' => 'Str0ngPassword99']);

    $this->post('/login', ['email' => $agent->email, 'password' => 'Str0ngPassword99'])
        ->assertRedirect(url('/b/ukph'));

    $this->assertAuthenticatedAs($agent);
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.login', 'causer_id' => $agent->getKey()]);
});

it('sends a global admin without brands to the brands screen', function (): void {
    $super = User::factory()->superAdmin()->create(['password' => 'SuperSecret12345']);

    $this->post('/login', ['email' => $super->email, 'password' => 'SuperSecret12345'])
        ->assertRedirect(route('admin.brands.index'));
});

it('refuses a suspended person', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $agent = agentOf(department($brand), ['password' => 'Str0ngPassword99', 'status' => MembershipStatus::Suspended]);

    $this->from('/login')
        ->post('/login', ['email' => $agent->email, 'password' => 'Str0ngPassword99'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('signs out a member who belongs to no brand with a clear message', function (): void {
    $user = User::factory()->create(['password' => 'Str0ngPassword99']);

    $this->post('/login', ['email' => $user->email, 'password' => 'Str0ngPassword99'])
        ->assertRedirect('/login')
        ->assertSessionHas('status');

    $this->assertGuest();
});
