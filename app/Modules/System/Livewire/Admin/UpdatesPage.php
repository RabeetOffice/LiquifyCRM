<?php

declare(strict_types=1);

namespace App\Modules\System\Livewire\Admin;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\Changelog;
use App\Modules\System\Support\SystemUpdater;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Updates (Super Admins and Admins): what changed in each version, whether
 * a new version is waiting, and the "Update system" button that applies it.
 */
#[Layout('components.layouts.app')]
#[Title('Updates')]
final class UpdatesPage extends Component
{
    use WithConfirmation;
    use WithToasts;

    public ?int $viewingLog = null;

    public function mount(): void
    {
        abort_unless($this->user()->canManageSystem(), 403);
    }

    public function update(): void
    {
        $updater = app(SystemUpdater::class);

        if (! $updater->available()) {
            $this->toastSuccess('Already up to date', 'LiquifyCRM is on version '.$updater->codeVersion().'.');

            return;
        }

        $pending = count($updater->pendingMigrations());

        $this->askConfirmation(
            'runUpdate',
            [false],
            'Update to version '.$updater->codeVersion().'?',
            $pending > 0
                ? 'The database is backed up first, then '.$pending.' database '.str('change')->plural($pending).' '.($pending === 1 ? 'is' : 'are').' applied. It takes a few seconds; keep this page open.'
                : 'This version needs no database changes. The caches are cleared and the version is recorded.',
            'Update system',
            'primary',
            password: $pending > 0,
        );
    }

    /** Only offered after a backup could not be made. */
    public function updateWithoutBackup(): void
    {
        $this->askConfirmation(
            'runUpdate',
            [true],
            'Update without a backup?',
            'The database changes are applied without a fresh backup. Do this only if you have another backup, for example from your hosting panel.',
            'Update without a backup',
            'danger',
            password: true,
        );
    }

    public function runUpdate(bool $withoutBackup = false): void
    {
        $this->ensureConfirmed();

        abort_unless($this->user()->canManageSystem(), 403);

        $deployment = app(SystemUpdater::class)->run($this->user(), $withoutBackup);

        if ($deployment->status === DeploymentStatus::Success) {
            $applied = count($deployment->migrations_run ?? []);

            $this->toastSuccess('LiquifyCRM is on version '.$deployment->release, $applied > 0 ? $applied.' database '.str('change')->plural($applied).' applied.' : 'No database changes were needed.');

            // The sidebar is not part of this screen: take its "New" badge away now rather than on the next page.
            $this->js("document.querySelectorAll('[data-update-flag]').forEach((badge) => badge.remove())");

            return;
        }

        $this->viewingLog = (int) $deployment->getKey();
        $this->toastDanger('The update did not finish', 'Nothing is lost. The reason is shown on this page.');
    }

    public function showLog(?int $deploymentId): void
    {
        $this->viewingLog = $this->viewingLog === $deploymentId ? null : $deploymentId;
    }

    public function render(): View
    {
        $updater = app(SystemUpdater::class);
        $installed = $updater->installedVersion();
        $new = array_column(Changelog::since($installed), 'version');
        $failure = $updater->lastFailure();

        return view('system::livewire.admin.updates-page', [
            'available' => $updater->available(),
            'codeVersion' => $updater->codeVersion(),
            'installed' => $installed,
            'lastUpdate' => $updater->lastUpdate()?->load('triggeredBy'),
            'pending' => $updater->pendingMigrations(),
            'failure' => $failure,
            'backupFailed' => (bool) ($failure?->meta['backup_failed'] ?? false),
            'releases' => Changelog::releases(),
            'newVersions' => $installed === null ? [] : $new,
            'types' => Changelog::TYPES,
            'history' => Deployment::query()->whereIn('type', [DeploymentType::Update->value, DeploymentType::DatabaseUpdate->value])->with('triggeredBy')->latest('id')->limit(8)->get(),
            'log' => $this->viewingLog !== null ? Deployment::query()->find($this->viewingLog) : null,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
