<?php

declare(strict_types=1);

use App\Modules\Auth\Actions\ManageTwoStep;
use App\Modules\Auth\Livewire\Admin\PeopleIndex;
use App\Modules\Auth\Livewire\Admin\PersonForm;
use App\Modules\Auth\Livewire\Admin\TwoStepPanel;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Support\BrandSharing;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\WebsiteLeadsBoard;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\WebsiteLeads;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * IT and Website leads people (user request, 27 Oct 2026). IT sees everybody,
 * sets passwords and looks after two-step for Managers, Agents and Website
 * leads people, and opens the System screens, never a brand, a lead or a sale.
 * A Website leads person reads the website leads of the brands chosen for
 * them on one dashboard, and nothing else.
 */

function itPerson(array $attributes = []): User
{
    return User::factory()->withTwoFactor()->create(['role' => GlobalRole::It, ...$attributes])->refresh();
}

function websiteLeadsPerson(array $brandIds, array $attributes = []): User
{
    $user = User::factory()->create(['role' => GlobalRole::WebsiteLeads, ...$attributes])->refresh();
    WebsiteLeads::choose($user, $brandIds);

    return $user;
}

it('lets IT into People, two-step, sign-in security, logs, updates and System, and nowhere else', function (): void {
    $brand = brand();
    $it = itPerson();

    foreach (['admin.people.index', 'admin.two-step', 'admin.sign-in', 'admin.logs', 'admin.updates', 'admin.system'] as $route) {
        $this->actingAs($it)->get(route($route))->assertOk();
    }

    foreach (['admin.dashboard', 'admin.brands.index', 'admin.sales', 'admin.integrations', 'admin.people.create', 'website-leads'] as $route) {
        $this->actingAs($it)->get(route($route))->assertForbidden();
    }

    $this->actingAs($it)->get(route('brand.leads.index', ['brand' => $brand->slug]))->assertNotFound();
    $this->actingAs($it)->get('/b')->assertRedirect(route('admin.people.index'));
});

it('asks IT for an authenticator app like an Admin', function (): void {
    $it = User::factory()->create(['role' => GlobalRole::It])->refresh();

    expect($it->requiresTwoFactor())->toBeTrue();
    $this->actingAs($it)->get(route('admin.people.index'))->assertRedirect(route('profile.security'));
});

it('lets IT set the password of Managers, Agents and Website leads people only', function (): void {
    $department = department(brand());
    $it = itPerson();

    expect($it->can('changePassword', agentOf($department)))->toBeTrue()
        ->and($it->can('changePassword', managerOf($department)))->toBeTrue()
        ->and($it->can('changePassword', websiteLeadsPerson([])))->toBeTrue()
        ->and($it->can('changePassword', admin()))->toBeFalse()
        ->and($it->can('changePassword', superAdmin()))->toBeFalse()
        ->and($it->can('changePassword', itPerson()))->toBeFalse()
        ->and($it->can('changePassword', $it))->toBeFalse()
        ->and($it->can('update', agentOf($department)))->toBeFalse()
        ->and($it->can('suspend', agentOf($department)))->toBeFalse();
});

it('lets an Admin set anybody\'s password but a Super Admin\'s', function (): void {
    $admin = admin();

    expect($admin->can('changePassword', admin()))->toBeTrue()
        ->and($admin->can('changePassword', itPerson()))->toBeTrue()
        ->and($admin->can('changePassword', superAdmin()))->toBeFalse();
});

it('sets a password from People, signs the person out and tells them', function (): void {
    $agent = agentOf(department(brand()));
    $it = itPerson();

    Livewire::actingAs($it)->test(PeopleIndex::class)
        ->call('openPassword', $agent->getKey())
        ->set('newPassword', 'Fresh-start-2026')
        ->set('newPassword_confirmation', 'Fresh-start-2026')
        ->call('savePassword')
        ->assertHasNoErrors();

    expect(Hash::check('Fresh-start-2026', (string) $agent->fresh()->password))->toBeTrue()
        ->and($agent->notifications()->count())->toBe(1);

    $this->assertDatabaseHas('activity_log', ['event' => 'members.password_set', 'causer_id' => $it->getKey()]);
});

it('refuses IT setting an Admin\'s password from People', function (): void {
    $other = admin();

    Livewire::actingAs(itPerson())->test(PeopleIndex::class)
        ->call('openPassword', $other->getKey())
        ->assertForbidden();
});

it('keeps the two-step codes closed until the password is given', function (): void {
    $agent = agentOf(department(brand()));
    $it = itPerson(['password' => 'Strong-password-1']);

    Livewire::actingAs($it)->test(TwoStepPanel::class)
        ->assertSee('The codes are closed')
        ->call('turnOn', $agent->getKey())
        ->assertForbidden();

    expect($agent->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('turns two-step on for a person and shows the code their app shows', function (): void {
    $agent = agentOf(department(brand()));
    $it = itPerson(['password' => 'Strong-password-1']);

    $panel = Livewire::actingAs($it)->test(TwoStepPanel::class)
        ->call('unlock')
        ->set('confirmPassword', 'Strong-password-1')
        ->call('runConfirmed')
        ->call('turnOn', $agent->getKey())
        ->assertSet('showQr', true);

    $agent->refresh();
    $secret = ManageTwoStep::secret($agent);
    $code = (new Google2FA)->getCurrentOtp((string) $secret);

    expect($agent->hasTwoFactorEnabled())->toBeTrue()
        ->and(ManageTwoStep::currentCode($agent))->toBe($code);

    $panel->assertSee(substr($code, 0, 3).' '.substr($code, 3));
    $this->assertDatabaseHas('activity_log', ['event' => 'auth.two_step_set', 'causer_id' => $it->getKey()]);
});

it('turns two-step on for everyone IT looks after, not for Admins', function (): void {
    $department = department(brand());
    $agent = agentOf($department);
    $manager = managerOf($department);
    $admin = User::factory()->admin()->create();
    $it = itPerson(['password' => 'Strong-password-1']);

    Livewire::actingAs($it)->test(TwoStepPanel::class)
        ->call('unlock')->set('confirmPassword', 'Strong-password-1')->call('runConfirmed')
        ->call('confirmTurnOnForEveryone')->call('runConfirmed');

    expect($agent->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($manager->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($admin->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('never lets IT reach an Admin\'s two-step', function (): void {
    $other = admin();
    $it = itPerson(['password' => 'Strong-password-1']);

    Livewire::actingAs($it)->test(TwoStepPanel::class)
        ->call('unlock')->set('confirmPassword', 'Strong-password-1')->call('runConfirmed')
        ->assertDontSee($other->email)
        ->call('showQrFor', $other->getKey())
        ->assertForbidden();
});

it('creates an IT person and a Website leads person from People', function (): void {
    $brand = brand();
    $admin = admin();

    Livewire::actingAs($admin)->test(PersonForm::class)
        ->set('kind', 'it')->set('name', 'Imran IT')->set('email', 'it@example.com')->set('password', 'Strong-password-1')
        ->call('save')->assertHasNoErrors();

    Livewire::actingAs($admin)->test(PersonForm::class)
        ->set('kind', 'website_leads')->set('name', 'Wajiha Web')->set('email', 'web@example.com')->set('password', 'Strong-pass-1')
        ->call('save')->assertHasErrors('leadBrandIds')
        ->set('leadBrandIds', [(string) $brand->getKey()])
        ->call('save')->assertHasNoErrors();

    $web = User::query()->where('email', 'web@example.com')->firstOrFail();

    expect(User::query()->where('email', 'it@example.com')->value('role'))->toBe(GlobalRole::It)
        ->and($web->role)->toBe(GlobalRole::WebsiteLeads)
        ->and(WebsiteLeads::chosenBrandIds($web))->toBe([(int) $brand->getKey()])
        ->and($web->memberships()->acrossBrands()->count())->toBe(0);
});

it('never puts an IT or Website leads person into a brand', function (): void {
    $brand = brand();

    expect(BrandSharing::refusal(admin(), itPerson(), $brand))->not->toBeNull()
        ->and(BrandSharing::refusal(admin(), websiteLeadsPerson([]), $brand))->not->toBeNull();
});

it('shows a Website leads person only the website leads of their brands', function (): void {
    $mine = brand(['name' => 'Mine']);
    $other = brand(['name' => 'Other']);
    $site = Site::factory()->create(['brand_id' => $mine->getKey(), 'name' => 'Main site']);
    $web = websiteLeadsPerson([$mine->getKey()]);

    Lead::factory()->create(['brand_id' => $mine->getKey(), 'source_type' => LeadSource::Website, 'source_id' => $site->getKey(), 'name' => 'Website Wendy']);
    Lead::factory()->create(['brand_id' => $mine->getKey(), 'source_type' => LeadSource::Facebook, 'name' => 'Facebook Fiona']);
    Lead::factory()->create(['brand_id' => $other->getKey(), 'source_type' => LeadSource::Website, 'name' => 'Other Oscar']);

    $this->actingAs($web)->get('/b')->assertRedirect(route('website-leads'));
    $this->actingAs($web)->get(route('website-leads'))->assertOk()
        ->assertSee('Website Wendy')
        ->assertDontSee('Facebook Fiona')
        ->assertDontSee('Other Oscar');

    $this->actingAs($web)->get(route('brand.leads.index', ['brand' => $mine->slug]))->assertNotFound();
    $this->actingAs($web)->get(route('admin.people.index'))->assertForbidden();
});

it('masks the contact for a Website leads person and never opens another brand\'s lead', function (): void {
    $mine = brand();
    $other = brand();
    $web = websiteLeadsPerson([$mine->getKey()]);
    $lead = Lead::factory()->create(['brand_id' => $mine->getKey(), 'source_type' => LeadSource::Website, 'email' => 'wendy@example.com']);
    $foreign = Lead::factory()->create(['brand_id' => $other->getKey(), 'source_type' => LeadSource::Website]);

    Livewire::actingAs($web)->test(WebsiteLeadsBoard::class)
        ->assertDontSee('wendy@example.com')
        ->call('openLead', $lead->getKey())
        ->assertSet('showLead', true)
        ->assertDontSee('wendy@example.com');

    Livewire::actingAs($web)->test(WebsiteLeadsBoard::class)
        ->call('openLead', $foreign->getKey())
        ->assertNotFound();
});

it('gives Admins every brand on the website leads dashboard, and nobody else gets in', function (): void {
    $brand = brand();
    Lead::factory()->create(['brand_id' => $brand->getKey(), 'source_type' => LeadSource::Website, 'name' => 'Website Wendy']);

    $this->actingAs(admin())->get(route('website-leads'))->assertOk()->assertSee('Website Wendy');
    $this->actingAs(memberOf($brand, BrandRole::Manager))->get(route('website-leads'))->assertForbidden();
});
