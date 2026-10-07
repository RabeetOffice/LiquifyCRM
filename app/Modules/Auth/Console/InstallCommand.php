<?php

declare(strict_types=1);

namespace App\Modules\Auth\Console;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * First-run setup: seeds roles and permissions and creates the first
 * Super Admin. Only a Super Admin can create another one later, so this
 * command refuses to run once one exists unless --force is given.
 */
final class InstallCommand extends Command
{
    protected $signature = 'liquify:install
        {--name= : Super Admin name}
        {--email= : Super Admin email}
        {--password= : Super Admin password (prompted if omitted)}
        {--force : Create an additional Super Admin even if one exists}';

    protected $description = 'Seed roles and permissions and create the first Super Admin';

    public function handle(): int
    {
        $this->components->info('Seeding roles and permissions');
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        if (User::superAdmins()->exists() && ! $this->option('force')) {
            $this->components->warn('A Super Admin already exists. Use --force to add another, or sign in and add one from the admin area.');

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: text('Super Admin name', required: true);
        $email = $this->option('email') ?: text('Super Admin email', required: true);
        $password = $this->option('password') ?: password('Password (at least 12 characters)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => GlobalRole::SuperAdmin,
            'is_protected' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        Audit::log('members.super_admin_created', $user, ['email' => $user->email], 'Super Admin created by installer', causer: $user, logName: 'members');

        $this->components->info("Super Admin {$user->email} created.");
        $this->newLine();
        $this->line('  Next steps:');
        $this->line('  1. php artisan serve');
        $this->line('  2. Open '.rtrim((string) config('app.url'), '/').'/admin and sign in.');
        $this->line('  3. You will be asked to set up two-factor authentication (required for Super Admins and Admins).');

        return self::SUCCESS;
    }
}
