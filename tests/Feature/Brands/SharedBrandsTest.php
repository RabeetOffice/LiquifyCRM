<?php

declare(strict_types=1);

use App\Modules\Auth\Livewire\Admin\PeopleIndex;
use App\Modules\Auth\Livewire\Admin\PersonForm;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Livewire\App\MemberForm;
use App\Modules\Brands\Livewire\App\MembersIndex;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\Permission;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * One person in several brands (user request, 14 Oct 2026): an Admin shares
 * more brands with a Manager or Agent on the People screen, with a role and a
 * department for each brand on its own. A Manager still adds their own people
 * to their own brand, but cannot pull in somebody from another brand.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->admin = admin();
    $this->uk = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->anz = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz']);
    $this->dublin = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dublin']);
    $this->ukSales = department($this->uk, ['name' => 'UK Front Sales']);
    $this->anzSales = department($this->anz, ['name' => 'ANZ Front Sales']);
    $this->anzUpsell = department($this->anz, ['name' => 'ANZ Upsell']);
    $this->suhaib = managerOf($this->ukSales, ['name' => 'Suhaib Ansari']);

    inBrand(null);
});

/** What the person may do inside one brand, as the permission table answers it there. */
function canIn(User $user, mixed $brand, Permission $permission): bool
{
    inBrand($brand);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    $can = $user->can($permission->value);
    inBrand(null);

    return $can;
}

it('lets an Admin give one person more brands, each with its own role and department', function (): void {
    Livewire::actingAs($this->admin)
        ->test(PersonForm::class, ['userId' => $this->suhaib->getKey()])
        ->assertSee('Give access to another brand')
        ->set('newBrandId', (string) $this->anz->getKey())
        ->set('newBrandRole', 'agent')
        ->set('newDepartmentId', (string) $this->anzSales->getKey())
        ->call('addBrand')
        ->assertHasNoErrors()
        ->set('newBrandId', (string) $this->dublin->getKey())
        ->set('newBrandRole', 'manager')
        ->call('addBrand')
        ->assertHasNoErrors()
        ->assertSee('ANZ Publishing House')
        ->assertSee('Dublin Publishing Press');

    $suhaib = $this->suhaib->fresh();

    expect($suhaib->brandRole($this->uk))->toBe(BrandRole::Manager)
        ->and($suhaib->brandRole($this->anz))->toBe(BrandRole::Agent)
        ->and($suhaib->brandRole($this->dublin))->toBe(BrandRole::Manager)
        ->and($suhaib->accessibleBrands()->pluck('slug')->sort()->values()->all())->toBe(['anz', 'dublin', 'ukph'])
        ->and($suhaib->belongsToDepartment($this->anzSales))->toBeTrue()
        ->and($suhaib->managesDepartment($this->anzSales))->toBeFalse()
        ->and($suhaib->managesDepartment($this->ukSales))->toBeTrue();

    // The permission table answers for each brand on its own.
    expect(canIn($suhaib, $this->uk, Permission::UsersManage))->toBeTrue()
        ->and(canIn($suhaib, $this->anz, Permission::UsersManage))->toBeFalse()
        ->and(canIn($suhaib, $this->anz, Permission::LeadsView))->toBeTrue()
        ->and(canIn($suhaib, $this->dublin, Permission::UsersManage))->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['event' => 'members.added', 'description' => 'Added Suhaib Ansari as Agent']);
});

it('changes the role and the department in one brand without touching the others', function (): void {
    $anz = app(AddMemberToBrand::class)->handle($this->anz, $this->suhaib, BrandRole::Agent, $this->anzSales, DepartmentRole::Agent, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(PersonForm::class, ['userId' => $this->suhaib->getKey()])
        ->call('changeBrandRole', $anz->getKey(), 'manager')
        ->call('changeBrandDepartment', $anz->getKey(), (string) $this->anzUpsell->getKey());

    $suhaib = $this->suhaib->fresh();

    expect($suhaib->brandRole($this->anz))->toBe(BrandRole::Manager)
        ->and($suhaib->managesDepartment($this->anzUpsell))->toBeTrue()
        ->and($suhaib->belongsToDepartment($this->anzSales))->toBeFalse()
        ->and(canIn($suhaib, $this->anz, Permission::UsersManage))->toBeTrue()
        // The UK brand is as it was.
        ->and($suhaib->brandRole($this->uk))->toBe(BrandRole::Manager)
        ->and($suhaib->managesDepartment($this->ukSales))->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(PersonForm::class, ['userId' => $this->suhaib->getKey()])
        ->call('changeBrandRole', $anz->getKey(), 'agent');

    $suhaib = $this->suhaib->fresh();

    expect($suhaib->managesDepartment($this->anzUpsell))->toBeFalse()
        ->and($suhaib->belongsToDepartment($this->anzUpsell))->toBeTrue()
        ->and(canIn($suhaib, $this->anz, Permission::UsersManage))->toBeFalse()
        ->and(canIn($suhaib, $this->uk, Permission::UsersManage))->toBeTrue();
});

it('removes one brand and keeps the rest', function (): void {
    $anz = app(AddMemberToBrand::class)->handle($this->anz, $this->suhaib, BrandRole::Agent, $this->anzSales, DepartmentRole::Agent, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(PersonForm::class, ['userId' => $this->suhaib->getKey()])
        ->call('confirmRemoveBrand', $anz->getKey())
        ->assertSet('confirm.method', 'removeBrand')
        // The dialog's Remove button, as in the browser.
        ->call('runConfirmed')
        ->assertHasNoErrors()
        ->assertSet('confirm', null);

    $suhaib = $this->suhaib->fresh();

    expect($suhaib->membershipFor($this->anz))->toBeNull()
        ->and($suhaib->belongsToDepartment($this->anzSales))->toBeFalse()
        ->and($suhaib->brandRole($this->uk))->toBe(BrandRole::Manager)
        ->and(canIn($suhaib, $this->anz, Permission::LeadsView))->toBeFalse()
        ->and(canIn($suhaib, $this->uk, Permission::LeadsView))->toBeTrue();
});

it('leaves sharing brands to Admins', function (): void {
    $agent = agentOf($this->ukSales, ['name' => 'Vimal Agent']);

    expect($this->admin->can('shareBrands', $agent))->toBeTrue()
        ->and($this->suhaib->can('shareBrands', $agent))->toBeFalse()
        ->and($this->admin->can('shareBrands', admin()))->toBeFalse();

    Livewire::actingAs($this->suhaib)
        ->test(PersonForm::class, ['userId' => $agent->getKey()])
        ->assertForbidden();
});

it('keeps a Manager from pulling somebody of another brand into theirs', function (): void {
    $other = agentOf($this->anzSales, ['name' => 'Omar Agent', 'email' => 'omar@example.com']);
    $ukAgent = agentOf($this->ukSales, ['name' => 'Vimal Agent']);
    inBrand($this->uk);

    $add = fn (string $email) => Livewire::actingAs($this->suhaib)
        ->test(MemberForm::class, ['brand' => $this->uk])
        ->set('name', 'Someone')
        ->set('email', $email)
        ->set('password', 'a-long-password-1')
        ->set('role', 'agent')
        ->set('department', (string) $this->ukSales->getKey())
        ->call('save');

    // Somebody of another brand: only an Admin may share brands.
    $add('omar@example.com')->assertHasErrors(['email']);
    // Somebody already in this brand cannot be added again (and so not changed by it).
    $add($ukAgent->email)->assertHasErrors(['email']);
    $add($this->admin->email)->assertHasErrors(['email']);

    expect($other->fresh()->membershipFor($this->uk))->toBeNull();

    // Inviting is held to the same rule.
    Livewire::actingAs($this->suhaib)
        ->test(MembersIndex::class, ['brand' => $this->uk])
        ->call('openInvite')
        ->set('inviteEmail', 'omar@example.com')
        ->set('inviteRole', 'agent')
        ->set('inviteDepartment', (string) $this->ukSales->getKey())
        ->call('sendInvite')
        ->assertHasErrors(['inviteEmail']);

    expect(Invitation::query()->acrossBrands()->count())->toBe(0);

    // A new person is added as before.
    $add('new.agent@example.com')->assertHasNoErrors();

    expect(User::query()->where('email', 'new.agent@example.com')->sole()->brandRole($this->uk))->toBe(BrandRole::Agent);
});

it('shows a person in several brands compactly on the People list', function (): void {
    app(AddMemberToBrand::class)->handle($this->anz, $this->suhaib, BrandRole::Agent, null, null, $this->admin);
    app(AddMemberToBrand::class)->handle($this->dublin, $this->suhaib, BrandRole::Agent, null, null, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(PeopleIndex::class)
        ->assertSee('3 brands')
        ->assertSee('Manager')
        ->assertSee('Agent');

    expect(Membership::query()->acrossBrands()->where('user_id', $this->suhaib->getKey())->count())->toBe(3);
});
