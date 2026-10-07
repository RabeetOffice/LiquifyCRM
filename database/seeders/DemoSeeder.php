<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Departments\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Local test data: two brands, departments, one Admin, Managers and Agents.
 * Run with: php artisan db:seed --class=DemoSeeder
 *
 * Every demo account uses the password "password".
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@liquify.test'],
            ['name' => 'Olivia Admin', 'password' => 'password', 'role' => GlobalRole::Admin, 'email_verified_at' => now()],
        );

        $ukph = Brand::query()->firstOrCreate(
            ['slug' => 'uk-publishing-house'],
            [
                'name' => 'UK Publishing House',
                'website' => 'https://ukpublishinghouse.co.uk',
                'currency' => 'GBP',
                'timezone' => 'Europe/London',
                'colors' => ['primary' => '#1D4ED8'],
                'created_by' => $admin->getKey(),
            ],
        );

        $anz = Brand::query()->firstOrCreate(
            ['slug' => 'anz-publishing-house'],
            [
                'name' => 'ANZ Publishing House',
                'website' => 'https://anzpublishinghouse.com',
                'currency' => 'AUD',
                'timezone' => 'Australia/Sydney',
                'colors' => ['primary' => '#047857'],
                'created_by' => $admin->getKey(),
            ],
        );

        $salesA = $this->department($ukph, 'Sales Team A', '#2563EB', $admin);
        $consultants = $this->department($ukph, 'Publishing Consultants', '#7C3AED', $admin);
        $anzSales = $this->department($anz, 'ANZ Sales', '#059669', $admin);

        $addMember = app(AddMemberToBrand::class);

        $managerA = $this->user('manager.a@liquify.test', 'Grace Manager');
        $addMember->handle($ukph, $managerA, BrandRole::Manager, $salesA, DepartmentRole::Manager, $admin);

        $managerC = $this->user('manager.c@liquify.test', 'Noah Consultant');
        $addMember->handle($ukph, $managerC, BrandRole::Manager, $consultants, DepartmentRole::Manager, $admin);

        $agent1 = $this->user('agent.one@liquify.test', 'Ava Agent');
        $addMember->handle($ukph, $agent1, BrandRole::Agent, $salesA, DepartmentRole::Agent, $managerA);

        $agent2 = $this->user('agent.two@liquify.test', 'Liam Agent');
        $addMember->handle($ukph, $agent2, BrandRole::Agent, $salesA, DepartmentRole::Agent, $managerA);

        $agent3 = $this->user('agent.three@liquify.test', 'Mia Agent');
        $addMember->handle($ukph, $agent3, BrandRole::Agent, $consultants, DepartmentRole::Agent, $managerC);

        $anzManager = $this->user('manager.anz@liquify.test', 'Ethan Sydney');
        $addMember->handle($anz, $anzManager, BrandRole::Manager, $anzSales, DepartmentRole::Manager, $admin);

        $anzAgent = $this->user('agent.anz@liquify.test', 'Zoe Melbourne');
        $addMember->handle($anz, $anzAgent, BrandRole::Agent, $anzSales, DepartmentRole::Agent, $anzManager);

        $this->command->info('Demo data ready. All demo passwords are "password".');
        $this->command->table(['Role', 'Email'], [
            ['Admin (all brands)', 'admin@liquify.test'],
            ['Manager, Sales Team A (UKPH)', 'manager.a@liquify.test'],
            ['Manager, Publishing Consultants (UKPH)', 'manager.c@liquify.test'],
            ['Agent, Sales Team A (UKPH)', 'agent.one@liquify.test'],
            ['Agent, Sales Team A (UKPH)', 'agent.two@liquify.test'],
            ['Agent, Publishing Consultants (UKPH)', 'agent.three@liquify.test'],
            ['Manager, ANZ Sales (ANZ)', 'manager.anz@liquify.test'],
            ['Agent, ANZ Sales (ANZ)', 'agent.anz@liquify.test'],
        ]);
    }

    private function department(Brand $brand, string $name, string $color, User $by): Department
    {
        return Department::query()->acrossBrands()->firstOrCreate(
            ['brand_id' => $brand->getKey(), 'name' => $name],
            ['color' => $color, 'created_by' => $by->getKey()],
        );
    }

    private function user(string $email, string $name): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password', 'email_verified_at' => now()],
        );
    }
}
