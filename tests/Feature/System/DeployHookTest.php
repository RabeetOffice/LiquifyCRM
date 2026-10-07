<?php

declare(strict_types=1);

use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Models\Deployment;

/*
 * POST /hooks/deploy/{token}: GitHub Actions' post-deploy callback.
 * The token comes from DEPLOY_HOOK_TOKEN (phpunit.xml sets test-deploy-token).
 */

it('rejects a wrong deploy token', function (): void {
    $this->postJson('/hooks/deploy/wrong-token', ['sha' => str_repeat('a', 40)])
        ->assertForbidden();

    expect(Deployment::query()->count())->toBe(0);
});

it('rejects every call when no token is configured', function (): void {
    config()->set('liquify.deploy_hook_token', '');

    $this->postJson('/hooks/deploy/', [])->assertNotFound();
    $this->postJson('/hooks/deploy/anything', [])->assertForbidden();
});

it('records the deployment and reports pending migrations', function (): void {
    $response = $this->postJson('/hooks/deploy/test-deploy-token', [
        'sha' => 'abcdef1234567890abcdef1234567890abcdef12',
        'branch' => 'main',
        'actor' => 'octocat',
        'run_url' => 'https://github.com/example/liquify/actions/runs/1',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['deployment_id', 'version', 'pending_migrations'])
        ->assertJsonPath('pending_migrations', []);

    $deployment = Deployment::query()->findOrFail($response->json('deployment_id'));

    expect($deployment->type)->toBe(DeploymentType::Deploy)
        ->and($deployment->status)->toBe(DeploymentStatus::Success)
        ->and($deployment->commit_sha)->toBe('abcdef1234567890abcdef1234567890abcdef12')
        ->and($deployment->shortSha())->toBe('abcdef1')
        ->and($deployment->branch)->toBe('main')
        ->and($deployment->meta['actor'])->toBe('octocat');

    $this->assertDatabaseHas('activity_log', ['event' => 'system.deployed']);
});

it('does not need a CSRF token', function (): void {
    $this->post('/hooks/deploy/test-deploy-token', ['sha' => 'f00'])->assertOk();
});
