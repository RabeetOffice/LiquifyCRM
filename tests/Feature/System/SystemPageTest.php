<?php

declare(strict_types=1);

use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Jobs\RunBackupJob;
use App\Modules\System\Jobs\RunDatabaseUpdateJob;
use App\Modules\System\Livewire\Admin\SystemPage;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\SystemStatus;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('is open to Super Admins and Admins, and to nobody else', function (): void {
    $brand = brand(['slug' => 'ukph']);

    $this->actingAs(superAdmin())->get('/admin/system')->assertOk();

    $this->flushSession();
    $this->actingAs(admin())->get('/admin/system')->assertOk();

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
        ->call('databaseUpdate')
        ->assertSet('confirm.method', 'runDatabaseUpdate')
        ->set('confirmPassword', 'wrong-password')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword')
        ->set('confirmPassword', 'SuperSecret12345')
        ->call('runConfirmed')
        ->assertHasNoErrors()
        ->assertSet('confirm', null);

    Queue::assertPushed(RunDatabaseUpdateJob::class);

    $deployment = Deployment::query()->latest('id')->firstOrFail();

    expect($deployment->type)->toBe(DeploymentType::DatabaseUpdate)
        ->and($deployment->status)->toBe(DeploymentStatus::Pending)
        ->and($deployment->triggered_by)->toBe($super->getKey());

    $this->assertDatabaseHas('activity_log', ['event' => 'system.database_update_requested']);
});

it('queues a backup from the Back up now button', function (): void {
    Queue::fake();

    Livewire::actingAs(superAdmin())
        ->test(SystemPage::class)
        ->call('backupNow')
        ->call('runConfirmed');

    Queue::assertPushed(RunBackupJob::class);
    expect(Deployment::query()->where('type', DeploymentType::Backup->value)->exists())->toBeTrue();
});
