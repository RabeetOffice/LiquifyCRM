<?php

declare(strict_types=1);

use App\Modules\Auth\Actions\ResetPasswordWithAuthenticator;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Notifications\AppNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

/*
 * A forgotten password can be replaced with the code from the person's
 * authenticator app, without an email.
 */

/**
 * A person with a real authenticator secret, and the code their app shows now.
 *
 * @return array{0: User, 1: string}
 */
function personWithAuthenticator(array $attributes = []): array
{
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->withTwoFactor()->create([
        'password' => 'OldPassword12345',
        'two_factor_secret' => encrypt($secret),
        ...$attributes,
    ])->refresh();

    return [$user, app(Google2FA::class)->getCurrentOtp($secret)];
}

/** @return array<string, string> */
function resetForm(string $email, string $code, string $password = 'NewPassword12345'): array
{
    return ['email' => $email, 'code' => $code, 'password' => $password, 'password_confirmation' => $password];
}

function wrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

it('offers the authenticator way on the forgot password screen', function (): void {
    $this->get('/forgot-password')->assertOk()->assertSee('Reset with its code instead');
    $this->get('/forgot-password/authenticator')->assertOk()->assertSee('Reset with your authenticator app');
});

it('saves a new password for the right code and does not sign the person in', function (): void {
    Notification::fake();
    [$user, $code] = personWithAuthenticator();

    $this->post('/forgot-password/authenticator', resetForm($user->email, $code))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();

    $this->assertGuest();
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.password_reset', 'causer_id' => $user->getKey()]);
    Notification::assertSentTo($user, AppNotification::class);
});

it('reads a code typed with a space, the way the app shows it', function (): void {
    [$user, $code] = personWithAuthenticator();

    $this->post('/forgot-password/authenticator', resetForm(mb_strtoupper($user->email), substr($code, 0, 3).' '.substr($code, 3)))
        ->assertRedirect(route('login'));

    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});

it('refuses a wrong code and keeps the old password', function (): void {
    [$user, $code] = personWithAuthenticator();

    $this->from('/forgot-password/authenticator')
        ->post('/forgot-password/authenticator', resetForm($user->email, wrongCode($code)))
        ->assertRedirect('/forgot-password/authenticator')
        ->assertSessionHasErrors('code');

    expect(Hash::check('OldPassword12345', $user->fresh()->password))->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['event' => 'auth.password_reset_failed', 'subject_id' => $user->getKey()]);
});

it('gives the same answer when the account has no authenticator app or does not exist', function (): void {
    $user = User::factory()->create(['password' => 'OldPassword12345']);

    $this->post('/forgot-password/authenticator', resetForm($user->email, '123456'))->assertSessionHasErrors('code');
    $this->post('/forgot-password/authenticator', resetForm('nobody@example.com', '123456'))->assertSessionHasErrors('code');

    expect(Hash::check('OldPassword12345', $user->fresh()->password))->toBeTrue()
        ->and(session('errors')->first('code'))->toBe('That email and code do not match. Check the email, wait for a new code and try again.');
});

it('refuses a suspended person', function (): void {
    [$user, $code] = personWithAuthenticator(['status' => MembershipStatus::Suspended]);

    $this->post('/forgot-password/authenticator', resetForm($user->email, $code))->assertSessionHasErrors('code');

    expect(Hash::check('OldPassword12345', $user->fresh()->password))->toBeTrue();
});

it('closes after five wrong tries, even for the right code', function (): void {
    [$user, $code] = personWithAuthenticator();

    foreach (range(1, ResetPasswordWithAuthenticator::MAX_PER_EMAIL) as $try) {
        $this->post('/forgot-password/authenticator', resetForm($user->email, wrongCode($code)))->assertSessionHasErrors('code');
    }

    $this->post('/forgot-password/authenticator', resetForm($user->email, $code))->assertSessionHasErrors('code');

    expect(Hash::check('OldPassword12345', $user->fresh()->password))->toBeTrue()
        ->and(session('errors')->first('code'))->toContain('Too many wrong tries');
});

it('does not count a mistyped new password as a wrong try', function (): void {
    [$user, $code] = personWithAuthenticator();

    foreach (range(1, ResetPasswordWithAuthenticator::MAX_PER_EMAIL + 1) as $try) {
        $this->post('/forgot-password/authenticator', [...resetForm($user->email, $code), 'password_confirmation' => 'Different12345'])
            ->assertSessionHasErrors('password');
    }

    $this->post('/forgot-password/authenticator', resetForm($user->email, $code))->assertRedirect(route('login'));
});

it('never accepts the same code twice', function (): void {
    [$user, $code] = personWithAuthenticator();

    $this->post('/forgot-password/authenticator', resetForm($user->email, $code))->assertRedirect(route('login'));
    $this->post('/forgot-password/authenticator', resetForm($user->email, $code, 'AnotherPassword999'))->assertSessionHasErrors('code');

    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});

it('asks for a 6-digit code and a strong password', function (): void {
    [$user] = personWithAuthenticator();

    $this->post('/forgot-password/authenticator', resetForm($user->email, '12ab', 'short'))
        ->assertSessionHasErrors(['code', 'password']);
});

it('sends a signed-in person away from the screen', function (): void {
    $this->actingAs(admin())->get('/forgot-password/authenticator')->assertRedirect();
});
