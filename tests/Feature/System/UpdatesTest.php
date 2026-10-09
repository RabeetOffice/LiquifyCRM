<?php

declare(strict_types=1);

use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Livewire\Admin\UpdatesPage;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\Changelog;
use App\Modules\System\Support\DatabaseBackup;
use App\Modules\System\Support\SystemUpdater;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Updates: after new code arrives, Admins see that a version is ready, read
 * what changed, and apply it with one button (backup, database changes,
 * caches, version recorded). Managers and Agents never see any of it.
 */

beforeEach(function (): void {
    Cache::flush();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->manager = managerOf(department($this->brand));
    $this->admin = admin();
    $this->version = Changelog::latestVersion();
});

it('lists the versions newest first with their changes', function (): void {
    $releases = Changelog::releases();

    expect($releases)->not->toBeEmpty()
        ->and($releases[0]['version'])->toBe($this->version)
        ->and(version_compare($releases[0]['version'], end($releases)['version'], '>='))->toBeTrue()
        ->and($releases[0]['changes'][0])->toHaveKeys(['type', 'text'])
        ->and(array_key_exists($releases[0]['changes'][0]['type'], Changelog::TYPES))->toBeTrue()
        ->and(array_column(Changelog::since('1.0.0'), 'version'))->not->toContain('1.0.0')
        ->and(Changelog::since($this->version))->toBe([])
        ->and(Changelog::since(null))->toHaveCount(1);
});

it('tells Admins that a version is ready until someone applies it, and never tells Managers', function (): void {
    // A fresh version, no database changes: the backup is not needed and no password is asked.
    $backup = Mockery::mock(DatabaseBackup::class);
    $backup->shouldNotReceive('run');
    app()->instance(DatabaseBackup::class, $backup);

    $this->actingAs($this->manager)->get('/b/ukph')->assertOk()->assertDontSee('Update system');
    $this->actingAs($this->manager)->get('/admin/updates')->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Version '.$this->version.' is ready')->assertSee('Update system');
    $this->get('/admin/updates')->assertOk()->assertSee('Version '.$this->version.' is ready')->assertSee('No database changes are needed')->assertSee('Update history');

    Livewire::actingAs($this->admin)
        ->test(UpdatesPage::class)
        ->call('update')
        ->call('runConfirmed')
        ->assertDispatched('toast')
        ->assertSee('LiquifyCRM is up to date')
        ->assertSee('Installed');

    $deployment = Deployment::query()->where('type', DeploymentType::Update->value)->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Success)
        ->and($deployment->release)->toBe($this->version)
        ->and($deployment->triggered_by)->toBe($this->admin->getKey())
        ->and(app(SystemUpdater::class)->installedVersion())->toBe($this->version)
        ->and(app(SystemUpdater::class)->available())->toBeFalse();

    $this->get('/admin')->assertOk()->assertDontSee('is ready')->assertSee('Up to date');
});

it('backs up, applies the waiting database changes and records them, and changes nothing when the backup fails', function (): void {
    // A database change that does nothing stands in for a real one.
    $directory = storage_path('framework/testing/update-'.uniqid());
    File::ensureDirectoryExists($directory);
    File::put($directory.'/2099_01_01_000000_update_test_change.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n\n    public function down(): void {}\n};\n");
    app('migrator')->path($directory);

    expect(app(SystemUpdater::class)->pendingMigrations())->toBe(['2099_01_01_000000_update_test_change']);

    $backup = Mockery::mock(DatabaseBackup::class);
    $backup->shouldReceive('run')->once()->andReturn(['ok' => false, 'output' => 'mysqldump was not found']);
    app()->instance(DatabaseBackup::class, $backup);

    // The password is asked because the database will change; the failed backup stops everything.
    Livewire::actingAs($this->admin)
        ->test(UpdatesPage::class)
        ->assertSee('1 database change will be applied after a backup')
        ->call('update')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertSee('The last update did not finish')
        ->assertSee('mysqldump was not found')
        ->assertSee('Update without a backup');

    expect(app(SystemUpdater::class)->pendingMigrations())->toHaveCount(1)
        ->and(app(SystemUpdater::class)->installedVersion())->toBeNull();

    // The Admin goes on without a backup: the change is applied and recorded.
    Livewire::actingAs($this->admin)
        ->test(UpdatesPage::class)
        ->call('updateWithoutBackup')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed')
        ->assertSee('LiquifyCRM is up to date');

    $done = Deployment::query()->where('type', DeploymentType::Update->value)->where('status', DeploymentStatus::Success->value)->sole();

    expect(app(SystemUpdater::class)->pendingMigrations())->toBe([])
        ->and($done->migrations_run)->toBe(['2099_01_01_000000_update_test_change'])
        ->and($done->log)->toContain('Backup skipped')
        ->and(app(SystemUpdater::class)->lastFailure())->toBeNull();

    File::deleteDirectory($directory);
});
