<?php

declare(strict_types=1);

use App\Modules\Auth\Livewire\ProfileSecurity;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * Password and 2FA: moving two-factor to a new app keeps the old one working
 * until the new one is confirmed, and the authenticator can be removed.
 */

function newAuthenticatorKey(): string
{
    return decrypt((string) session('two_factor.replacement'));
}

it('moves two-factor to a new app only once a code from the new app is confirmed', function (): void {
    $admin = admin();
    $oldSecret = $admin->two_factor_secret;

    $page = Livewire::actingAs($admin)->test(ProfileSecurity::class)
        ->call('changeAuthenticator')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertSee('Move to a new authenticator app');

    $key = newAuthenticatorKey();

    // A wrong code changes nothing: the old app still works.
    $page->set('newCode', '000000')->call('confirmNewAuthenticator')->assertHasErrors('newCode');
    expect($admin->fresh()->two_factor_secret)->toBe($oldSecret);

    $page->set('newCode', app(Google2FA::class)->getCurrentOtp($key))->call('confirmNewAuthenticator')->assertHasNoErrors();

    $fresh = $admin->fresh();
    expect(decrypt($fresh->two_factor_secret))->toBe($key)
        ->and($fresh->hasTwoFactorEnabled())->toBeTrue()
        ->and(session('two_factor.replacement'))->toBeNull();

    $this->assertDatabaseHas('activity_log', ['event' => 'auth.two_factor_changed', 'causer_id' => $admin->getKey()]);
});

it('keeps the old app when the move is cancelled', function (): void {
    $admin = admin();
    $oldSecret = $admin->two_factor_secret;

    Livewire::actingAs($admin)->test(ProfileSecurity::class)
        ->call('changeAuthenticator')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->call('cancelNewAuthenticator')
        ->assertDontSee('Move to a new authenticator app');

    expect($admin->fresh()->two_factor_secret)->toBe($oldSecret)
        ->and(session('two_factor.replacement'))->toBeNull();
});

it('asks the password before a new app or the recovery codes', function (): void {
    $admin = admin();

    Livewire::actingAs($admin)->test(ProfileSecurity::class)->call('doChangeAuthenticator')->assertForbidden();
    Livewire::actingAs($admin)->test(ProfileSecurity::class)->call('doShowRecoveryCodes')->assertForbidden();

    Livewire::actingAs($admin)->test(ProfileSecurity::class)
        ->call('changeAuthenticator')
        ->set('confirmPassword', 'wrong-password')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword');

    expect(session('two_factor.replacement'))->toBeNull();
});

it('lets an Admin remove the authenticator, then sends them to set up a new one', function (): void {
    $admin = admin();

    Livewire::actingAs($admin)->test(ProfileSecurity::class)
        ->assertSee('Remove')
        ->call('disableTwoFactor')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed');

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $response = $this->actingAs($admin->fresh())->get('/admin/brands');
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('profile/security');
});

it('shows the recovery codes after the password', function (): void {
    $admin = admin();

    Livewire::actingAs($admin)->test(ProfileSecurity::class)
        ->call('showCodes')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertSet('showRecoveryCodes', true)
        ->assertSee($admin->recoveryCodes()[0]);
});
