<?php

declare(strict_types=1);

namespace App\Modules\System\Http\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\SystemStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/**
 * POST /hooks/deploy/{token}: called by GitHub Actions after Deployer has
 * switched the "current" symlink. Records the deployment, clears the app
 * cache and tells every Super Admin when database updates are waiting.
 */
final class DeployHookController
{
    public function __invoke(Request $request, string $token, SystemStatus $status): JsonResponse
    {
        $expected = (string) config('liquify.deploy_hook_token');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            abort(403, 'Invalid deploy token.');
        }

        $validated = $request->validate([
            'sha' => ['nullable', 'string', 'max:40'],
            'branch' => ['nullable', 'string', 'max:100'],
            'release' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'string', 'max:100'],
            'run_url' => ['nullable', 'string', 'max:500'],
        ]);

        Artisan::call('cache:clear');
        Artisan::call('permission:cache-reset');

        $pending = $status->pendingMigrations();

        $deployment = Deployment::query()->create([
            'type' => DeploymentType::Deploy,
            'status' => DeploymentStatus::Success,
            'commit_sha' => $validated['sha'] ?? null,
            'branch' => $validated['branch'] ?? null,
            'release' => $validated['release'] ?? null,
            'started_at' => now(),
            'finished_at' => now(),
            'pending_migrations' => $pending,
            'log' => 'Release switched live by GitHub Actions.'.(($validated['run_url'] ?? null) ? ' Run: '.$validated['run_url'] : ''),
            'meta' => ['actor' => $validated['actor'] ?? null, 'run_url' => $validated['run_url'] ?? null],
        ]);

        Audit::log('system.deployed', $deployment, [
            'sha' => $deployment->commit_sha,
            'branch' => $deployment->branch,
            'pending_migrations' => count($pending),
        ], 'Deployed '.($deployment->shortSha() ?? 'new release'), logName: 'system');

        if ($pending !== []) {
            Notification::send(User::superAdmins()->get(), new AppNotification(
                count($pending).' pending database update'.(count($pending) === 1 ? '' : 's'),
                'A new release is live. Open System and click "Backup and run database update".',
                'warning',
                url('/admin/system'),
                actionLabel: 'Open System',
            ));
        }

        return response()->json([
            'deployment_id' => $deployment->getKey(),
            'version' => $status->version(),
            'pending_migrations' => $pending,
        ]);
    }
}
