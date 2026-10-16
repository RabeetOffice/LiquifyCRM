<?php

declare(strict_types=1);

namespace App\Modules\Sites\Livewire\Concerns;

use App\Modules\Sites\Models\Site;

/**
 * Form fields for a website read straight from its database (the encrypted
 * pull_config). The password is never sent back to the browser; an empty
 * password field keeps the stored one. Every other PHP website needs no
 * details at all: liquify.php in its root folder finds them itself.
 */
trait EditsPullConfig
{
    public string $dbHost = '';

    public string $dbPort = '3306';

    public string $dbName = '';

    public string $dbUser = '';

    public string $dbPassword = '';

    public string $dbTable = 'leads';

    protected function fillPullConfig(Site $site): void
    {
        $config = $site->pull_config ?? [];

        $this->dbHost = (string) ($config['database']['host'] ?? '');
        $this->dbPort = (string) ($config['database']['port'] ?? '3306');
        $this->dbName = (string) ($config['database']['database'] ?? '');
        $this->dbUser = (string) ($config['database']['username'] ?? '');
        $this->dbTable = (string) ($config['database']['table'] ?? 'leads');
        $this->dbPassword = '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedPullConfig(Site $site): array
    {
        $stored = $site->pull_config ?? [];

        $this->validate([
            'dbHost' => ['required', 'string', 'max:190'],
            'dbPort' => ['nullable', 'integer', 'between:1,65535'],
            'dbName' => ['required', 'string', 'max:64'],
            'dbUser' => ['required', 'string', 'max:64'],
            'dbPassword' => ['nullable', 'string', 'max:255'],
            'dbTable' => ['nullable', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
        ], attributes: [
            'dbHost' => 'database host', 'dbPort' => 'port', 'dbName' => 'database name', 'dbUser' => 'database user',
            'dbPassword' => 'database password', 'dbTable' => 'table name',
        ]);

        $config = ['driver' => 'database', 'database' => [
            'host' => trim($this->dbHost),
            'port' => (int) ($this->dbPort ?: 3306),
            'database' => trim($this->dbName),
            'username' => trim($this->dbUser),
            'password' => filled($this->dbPassword) ? $this->dbPassword : (string) ($stored['database']['password'] ?? ''),
            'table' => trim($this->dbTable) ?: 'leads',
        ]];

        $this->dbPassword = '';

        return $config;
    }
}
