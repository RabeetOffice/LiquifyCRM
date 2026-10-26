<?php

declare(strict_types=1);

use App\Modules\Auth\Actions\ChangeSignInRules;
use App\Modules\Auth\Actions\DecideDevice;
use App\Modules\Auth\Enums\DeviceStatus;
use App\Modules\Auth\Enums\NetworkRule;
use App\Modules\Auth\Enums\PhoneRule;
use App\Modules\Auth\Enums\SignInRefusal;
use App\Modules\Auth\Livewire\Admin\PersonForm;
use App\Modules\Auth\Livewire\Admin\SignInSecurity;
use App\Modules\Auth\Models\SignInAddress;
use App\Modules\Auth\Models\SignInDevice;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Settings\SignInSettings;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

/*
 * Sign-in security (26 Oct 2026): the office network, phones and tablets, and
 * blocked addresses, checked at sign-in and on every request after it.
 */

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const DESKTOP_SITE = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

beforeEach(function (): void {
    $this->brand = brand(['slug' => 'ukph']);
    $this->agent = agentOf(department($this->brand), ['name' => 'Ali Khan', 'password' => 'Str0ngPassword99']);
    $this->admin = admin(['password' => 'AdminPassword123']);
});

/** Sign in as the agent from a browser and address of the test's choosing. */
function signIn(object $test, string $agent, string $ip = '203.0.113.50', ?string $token = 'phone-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', array $extra = []): mixed
{
    $request = $test->withHeader('User-Agent', $agent)->withServerVariables(['REMOTE_ADDR' => $ip]);

    if ($token !== null) {
        $request = $request->withCookie(SignInDevice::COOKIE, $token);
    }

    return $request->from('/login')->post('/login', ['email' => $test->agent->email, 'password' => 'Str0ngPassword99', ...$extra]);
}

function rulesForEverybody(NetworkRule $network, PhoneRule $phones): void
{
    app(ChangeSignInRules::class)->forEverybody($network, $phones);
}

it('lets a computer in when phones are refused', function (): void {
    rulesForEverybody(NetworkRule::Anywhere, PhoneRule::None);

    signIn($this, WINDOWS)->assertRedirect(url('/b/ukph'));

    $this->assertAuthenticatedAs($this->agent);
});

it('refuses a phone when phones are not allowed, and logs who tried', function (): void {
    rulesForEverybody(NetworkRule::Anywhere, PhoneRule::None);

    signIn($this, IPHONE)->assertRedirect('/login')->assertSessionHasErrors(['email' => SignInRefusal::NoPhones->message()]);

    $this->assertGuest();
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.refused', 'causer_id' => $this->agent->getKey()]);
});

it('lets one person use a phone while everybody else is refused', function (): void {
    rulesForEverybody(NetworkRule::Anywhere, PhoneRule::None);
    app(ChangeSignInRules::class)->forPerson($this->agent, null, PhoneRule::Any);

    signIn($this, ANDROID)->assertRedirect(url('/b/ukph'));

    $this->assertAuthenticatedAs($this->agent);
    expect(SignInDevice::query()->sole())
        ->status->toBe(DeviceStatus::Seen)
        ->name->toBe('Android phone (SM-S918B) · Chrome');
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.login', 'description' => 'Signed in on a phone']);
});

it('keeps a new phone waiting for an Admin, who is told, and lets it in once approved', function (): void {
    rulesForEverybody(NetworkRule::Anywhere, PhoneRule::Approved);

    signIn($this, IPHONE)->assertSessionHasErrors(['email' => SignInRefusal::PhoneWaiting->message()]);
    $this->assertGuest();

    $device = SignInDevice::query()->sole();
    expect($device->status)->toBe(DeviceStatus::Waiting)
        ->and(DatabaseNotification::query()->where('notifiable_id', $this->admin->getKey())->count())->toBe(1);

    // Trying again does not tell the Admin twice.
    signIn($this, IPHONE);
    expect(DatabaseNotification::query()->count())->toBe(1);

    app(DecideDevice::class)->approve($device, $this->admin);

    signIn($this, IPHONE)->assertRedirect(url('/b/ukph'));
    $this->assertAuthenticatedAs($this->agent);

    // Another phone of the same person still waits.
    auth()->logout();
    signIn($this, IPHONE, token: 'other-phone-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->assertSessionHasErrors('email');
});

it('refuses a blocked phone to everybody who used it, whatever their rule', function (): void {
    app(ChangeSignInRules::class)->forPerson($this->agent, null, PhoneRule::Any);
    signIn($this, IPHONE);
    auth()->logout();

    app(DecideDevice::class)->block(SignInDevice::query()->sole(), $this->admin);

    signIn($this, IPHONE)->assertSessionHasErrors(['email' => SignInRefusal::PhoneBlocked->message()]);
    $this->assertGuest();
});

it('knows a phone asking for the desktop site by its small touch screen', function (): void {
    rulesForEverybody(NetworkRule::Anywhere, PhoneRule::None);

    signIn($this, DESKTOP_SITE, extra: ['device' => json_encode(['touch' => 5, 'w' => 412, 'h' => 915, 'mobile' => false])])
        ->assertSessionHasErrors(['email' => SignInRefusal::NoPhones->message()]);

    $this->assertGuest();
});

it('lets office-only people in from an office address or range and nowhere else', function (): void {
    app(ChangeSignInRules::class)->addAddress(SignInAddress::OFFICE, '203.0.113.0/24', 'Lahore office', $this->admin);
    app(ChangeSignInRules::class)->forPerson($this->agent, NetworkRule::Office, null);

    signIn($this, WINDOWS, '198.51.100.9')->assertSessionHasErrors(['email' => SignInRefusal::OutsideOffice->message()]);
    $this->assertGuest();

    signIn($this, WINDOWS, '203.0.113.77')->assertRedirect(url('/b/ukph'));
    $this->assertAuthenticatedAs($this->agent);
});

it('signs out somebody already working once they are outside the office', function (): void {
    app(ChangeSignInRules::class)->addAddress(SignInAddress::OFFICE, '203.0.113.7', null, $this->admin);

    $this->actingAs($this->agent)->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get('/b/ukph')->assertOk();

    rulesForEverybody(NetworkRule::Office, PhoneRule::Any);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get('/b/ukph')->assertRedirect(route('login'));
    $this->assertGuest();

    // The sign-in page says why, until they sign in again.
    $this->get('/login')->assertSee(SignInRefusal::OutsideOffice->message());
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.refused', 'causer_id' => $this->agent->getKey()]);
});

it('turns a blocked address away from every page, signed in or not', function (): void {
    app(ChangeSignInRules::class)->addAddress(SignInAddress::BLOCKED, '198.51.100.23', 'Former agent', $this->admin);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->get('/login')
        ->assertForbidden()->assertSee('This connection is blocked');

    $this->actingAs($this->agent)->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->get('/b/ukph')->assertRedirect(route('login'));
    $this->assertGuest();

    // Other addresses and the public pages are not affected.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])->get('/login')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->get('/privacy')->assertOk();
});

it('gives every browser its own name, the cookie that lets a phone be approved', function (): void {
    $this->get('/login')->assertCookie(SignInDevice::COOKIE);
});

it('checks nothing while the rules are switched off in the env file', function (): void {
    rulesForEverybody(NetworkRule::Anywhere, PhoneRule::None);
    config(['liquify.sign_in_rules' => false]);

    signIn($this, IPHONE)->assertRedirect(url('/b/ukph'));
    $this->assertAuthenticatedAs($this->agent);
});

it('never lets an Admin save a rule that would sign them out', function (): void {
    Livewire::actingAs($this->admin)->test(SignInSecurity::class)
        ->set('network', 'office')->call('saveRules')
        ->assertHasErrors('network');

    // An office address that is not theirs: still refused.
    app(ChangeSignInRules::class)->addAddress(SignInAddress::OFFICE, '203.0.113.7', null, $this->admin);
    Livewire::actingAs($this->admin)->test(SignInSecurity::class)
        ->set('network', 'office')->call('saveRules')
        ->assertHasErrors('network');

    // From the office: saved.
    Livewire::actingAs($this->admin)->test(SignInSecurity::class)
        ->call('addMyAddress')
        ->set('network', 'office')->set('phones', 'approved')->call('saveRules')
        ->assertHasNoErrors();

    expect(app(SignInSettings::class)->network)->toBe('office');
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.rules_changed']);
});

it('refuses to block the Admin\'s own address and checks typed addresses', function (): void {
    Livewire::actingAs($this->admin)->test(SignInSecurity::class)
        ->set('blockedAddress', '127.0.0.1')->call('addBlocked')->assertHasErrors('blockedAddress')
        ->set('blockedAddress', 'not an address')->call('addBlocked')->assertHasErrors('blockedAddress')
        ->set('blockedAddress', '198.51.100.0/24')->set('blockedLabel', 'Spam')->call('addBlocked')->assertHasNoErrors();

    expect(SignInAddress::query()->where('kind', SignInAddress::BLOCKED)->pluck('address')->all())->toBe(['198.51.100.0/24']);
});

it('approves and blocks phones from the screen', function (): void {
    rulesForEverybody(NetworkRule::Anywhere, PhoneRule::Approved);
    signIn($this, IPHONE);
    $device = SignInDevice::query()->sole();

    Livewire::actingAs($this->admin)->test(SignInSecurity::class)
        ->assertSee('Ali Khan')->assertSee('iPhone · Safari')->assertSee('Waiting for approval')
        ->call('approveDevice', $device->getKey());

    expect($device->fresh()?->status)->toBe(DeviceStatus::Approved);

    Livewire::actingAs($this->admin)->test(SignInSecurity::class)->call('blockDevice', $device->getKey());
    expect($device->fresh()?->status)->toBe(DeviceStatus::Blocked);
});

it('sets a person\'s own rule on People › Edit', function (): void {
    app(ChangeSignInRules::class)->addAddress(SignInAddress::OFFICE, '203.0.113.7', null, $this->admin);

    Livewire::actingAs($this->admin)->test(PersonForm::class, ['userId' => $this->agent->getKey()])
        ->set('signInNetwork', 'office')->set('signInPhones', 'none')
        ->call('save')->assertHasNoErrors();

    expect($this->agent->fresh())
        ->sign_in_network->toBe('office')
        ->sign_in_phones->toBe('none');

    // Their own: never one that would sign them out from here.
    Livewire::actingAs($this->admin)->test(PersonForm::class, ['userId' => $this->admin->getKey()])
        ->set('signInNetwork', 'office')->call('save')
        ->assertHasErrors('signInNetwork');

    expect(User::query()->find($this->admin->getKey())?->sign_in_network)->toBeNull();
});
