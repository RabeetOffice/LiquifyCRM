<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\InviteMember;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Brands\Notifications\InvitationNotification;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->admin = admin();
});

it('creates an invitation and emails the signed link', function (): void {
    $invitation = app(InviteMember::class)->handle(
        $this->brand,
        'New.Agent@Example.com',
        BrandRole::Agent,
        $this->department,
        DepartmentRole::Agent,
        'New Agent',
        $this->admin,
    );

    expect($invitation->email)->toBe('new.agent@example.com')
        ->and(strlen($invitation->token))->toBe(64)
        ->and($invitation->isUsable())->toBeTrue()
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        fn (InvitationNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'new.agent@example.com',
    );

    $this->assertDatabaseHas('activity_log', ['event' => 'members.invited', 'brand_id' => $this->brand->getKey()]);
});

it('replaces an unused invitation for the same email', function (): void {
    app(InviteMember::class)->handle($this->brand, 'agent@example.com', BrandRole::Agent, $this->department, by: $this->admin);
    app(InviteMember::class)->handle($this->brand, 'agent@example.com', BrandRole::Manager, $this->department, by: $this->admin);

    expect(Invitation::query()->acrossBrands()->where('email', 'agent@example.com')->count())->toBe(1)
        ->and(Invitation::query()->acrossBrands()->first()->role)->toBe(BrandRole::Manager);
});

it('shows the accept page for a valid token', function (): void {
    $invitation = app(InviteMember::class)->handle($this->brand, 'agent@example.com', BrandRole::Agent, $this->department, by: $this->admin);

    $this->get('/invite/'.$invitation->token)
        ->assertOk()
        ->assertSee('UK Publishing House')
        ->assertSee('Sales Team A')
        ->assertSee('Create my account');
});

it('creates the account, membership and department seat when accepted', function (): void {
    $invitation = app(InviteMember::class)->handle($this->brand, 'agent@example.com', BrandRole::Agent, $this->department, by: $this->admin);

    $response = $this->post('/invite/'.$invitation->token, [
        'name' => 'Ava Agent',
        'password' => 'Str0ngPassword99',
        'password_confirmation' => 'Str0ngPassword99',
    ]);

    $response->assertRedirect('/b/ukph');
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'agent@example.com')->firstOrFail();

    expect($user->name)->toBe('Ava Agent')
        ->and($user->brandRole($this->brand))->toBe(BrandRole::Agent)
        ->and($user->belongsToDepartment($this->department))->toBeTrue()
        ->and($invitation->fresh()->isAccepted())->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['event' => 'members.invite_accepted', 'brand_id' => $this->brand->getKey()]);
});

it('attaches an existing account instead of creating a second one', function (): void {
    $existing = User::factory()->create(['email' => 'grace@example.com']);
    $invitation = app(InviteMember::class)->handle($this->brand, 'grace@example.com', BrandRole::Manager, $this->department, by: $this->admin);

    $this->get('/invite/'.$invitation->token)->assertOk()->assertSee('already exists');

    $this->post('/invite/'.$invitation->token, ['accept' => '1'])->assertRedirect('/b/ukph');

    expect(User::query()->where('email', 'grace@example.com')->count())->toBe(1)
        ->and($existing->fresh()->brandRole($this->brand))->toBe(BrandRole::Manager)
        ->and($existing->fresh()->managesDepartment($this->department))->toBeTrue();
});

it('refuses expired and already-used invitations', function (): void {
    $expired = app(InviteMember::class)->handle($this->brand, 'old@example.com', BrandRole::Agent, by: $this->admin);
    $expired->forceFill(['expires_at' => now()->subDay()])->save();

    $this->get('/invite/'.$expired->token)->assertOk()->assertSee('expired');
    $this->post('/invite/'.$expired->token, ['name' => 'X', 'password' => 'Str0ngPassword99', 'password_confirmation' => 'Str0ngPassword99'])
        ->assertOk()
        ->assertSee('be used');

    $used = app(InviteMember::class)->handle($this->brand, 'used@example.com', BrandRole::Agent, by: $this->admin);
    $used->forceFill(['accepted_at' => now()])->save();

    $this->get('/invite/'.$used->token)->assertOk()->assertSee('already been accepted');
    $this->get('/invite/not-a-real-token')->assertOk()->assertSee('not valid');
});

it('validates the password on accept', function (): void {
    $invitation = app(InviteMember::class)->handle($this->brand, 'weak@example.com', BrandRole::Agent, by: $this->admin);

    $this->from('/invite/'.$invitation->token)
        ->post('/invite/'.$invitation->token, ['name' => 'Weak', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertRedirect('/invite/'.$invitation->token)
        ->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'weak@example.com')->exists())->toBeFalse();
});
