<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Deployer recipe: GitHub Actions -> Hostinger
|--------------------------------------------------------------------------
| The build (composer --no-dev, npm run build, tests) happens in GitHub
| Actions. This recipe uploads the built tree into a new releases/<n>
| folder, links the shared .env and storage, warms the caches and switches
| the "current" symlink. Migrations are NEVER run here: the Super Admin runs
| "Backup and run database update" on the System screen.
|
| Host details come from GitHub environment secrets (see .github/workflows).
*/

namespace Deployer;

require 'recipe/laravel.php';

set('application', 'LiquifyCRM');
set('keep_releases', 5);
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_recursive', true);
set('bin/php', fn () => getenv('DEPLOY_PHP') ?: which('php'));

// Shared between releases: the only place .env lives.
add('shared_files', ['.env']);
add('shared_dirs', ['storage']);
add('writable_dirs', [
    'bootstrap/cache',
    'storage',
    'storage/app',
    'storage/app/public',
    'storage/app/backups',
    'storage/framework',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
]);

// Hosts ---------------------------------------------------------------------

host('production')
    ->setHostname((string) getenv('DEPLOY_HOST'))
    ->setPort((int) (getenv('DEPLOY_PORT') ?: 22))
    ->setRemoteUser((string) getenv('DEPLOY_USER'))
    ->setDeployPath((string) getenv('DEPLOY_PATH'))
    ->set('branch', 'main');

host('staging')
    ->setHostname((string) getenv('DEPLOY_HOST'))
    ->setPort((int) (getenv('DEPLOY_PORT') ?: 22))
    ->setRemoteUser((string) getenv('DEPLOY_USER'))
    ->setDeployPath((string) getenv('DEPLOY_PATH'))
    ->set('branch', 'develop');

// Tasks ---------------------------------------------------------------------

// Upload the tree built in CI instead of cloning from git on the server.
task('deploy:update_code', function (): void {
    upload('./', '{{release_path}}', [
        'options' => [
            '--exclude=.git',
            '--exclude=.github',
            '--exclude=node_modules',
            '--exclude=tests',
            '--exclude=storage',
            '--exclude=.env',
            '--exclude=.env.*',
            '--exclude=.dep',
            '--exclude=phpunit.xml',
            '--exclude=phpstan.neon',
            '--exclude=pint.json',
        ],
    ]);
})->desc('Upload the release built by GitHub Actions');

task('artisan:filament:assets', artisan('filament:assets'))->desc('Publish Filament assets');
task('artisan:filament:optimize', artisan('filament:optimize'))->desc('Cache Filament components and icons');
task('artisan:permission:cache-reset', artisan('permission:cache-reset'))->desc('Reset the permission cache');

// The Laravel recipe's default deploy task runs migrations; ours does not.
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'artisan:storage:link',
    'artisan:filament:assets',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:view:cache',
    'artisan:event:cache',
    'artisan:filament:optimize',
    'artisan:permission:cache-reset',
    'deploy:publish',
])->desc('Deploy LiquifyCRM (no migrations)');

// vendor/ is uploaded already; nothing to install on the server.
task('deploy:vendors', function (): void {
    writeln('vendor/ was built in CI, skipping composer install on the server.');
});

after('deploy:failed', 'deploy:unlock');
