<?php

declare(strict_types=1);

use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Filament\Admin\Pages\SystemPage;
use App\Modules\System\Jobs\RunBackupJob;
use App\Modules\System\Jobs\RunDatabaseUpdateJob;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\SystemStatus;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    filament()->setCurrentPanel(filament()->getPanel('admin'));
});

it('is reserved for Super Admins', function (): void {
    $brand = brand(['slug' => 'ukph']);

    $this->actingAs(superAdmin())->get('/admin/system')->assertOk();

    $this->flushSession();
    $this->actingAs(admin())->get('/admin/system')->assertForbidden();

    $this->flushSession();
    $this->actingAs(managerOf(department($brand)))->get('/admin/system')->assertForbidden();
});

it('shows the database as up to date when nothing is pending', function (): void {
    expect(app(SystemStatus::class)->pendingMigrations())->toBe([])
        ->and(app(SystemStatus::class)->version())->not->toBe('');

    $this->actingAs(superAdmin())
        ->get('/admin/system')
        ->assertOk()
        ->assertSee('Database is up to date')
        ->assertSee('Backup and run database update');
});

it('queues a backup-then-migrate job after the password is confirmed', function (): void {
    Queue::fake();
    $super = superAdmin(['password' => 'SuperSecret12345']);

    Livewire::actingAs($super)
        ->test(SystemPage::class)
        ->callAction('databaseUpdate', data: ['current_password' => 'SuperSecret12345'])
        ->assertHasNoActionErrors();

    Queue::assertPushed(RunDatabaseUpdateJob::class);

    $deployment = Deployment::query()->latest('id')->firstOrFail();

    expect($deployment->type)->toBe(DeploymentType::DatabaseUpdate)
        ->and($deployment->status)->toBe(DeploymentStatus::Pending)
        ->and($deployment->triggered_by)->toBe($super->getKey());

    $this->assertDatabaseHas('activity_log', ['event' => 'system.database_update_requested']);
});

it('refuses the database update with a wrong password', function (): void {
    Queue::fake();
    $super = superAdmin(['password' => 'SuperSecret12345']);

    Livewire::actingAs($super)
        ->test(SystemPage::class)
        ->callAction('databaseUpdate', data: ['current_password' => 'nope'])
        ->assertHasActionErrors(['current_password']);

    Queue::assertNothingPushed();
    expect(Deployment::query()->count())->toBe(0);
});

it('queues a backup from the Back up now button', function (): void {
    Queue::fake();

    Livewire::actingAs(superAdmin())
        ->test(SystemPage::class)
        ->callAction('backupNow')
        ->assertHasNoActionErrors();

    Queue::assertPushed(RunBackupJob::class);
    expect(Deployment::query()->where('type', DeploymentType::Backup->value)->count())->toBe(1);
});

it('lists deployment history', function (): void {
    Deployment::query()->create([
        'type' => DeploymentType::Deploy,
        'status' => DeploymentStatus::Success,
        'commit_sha' => '1234567abcdef',
        'branch' => 'main',
    ]);

    $this->actingAs(superAdmin())
        ->get('/admin/system')
        ->assertOk()
        ->assertSee('1234567');
});
