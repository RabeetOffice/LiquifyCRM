<?php

declare(strict_types=1);

namespace App\Modules\Sites\Livewire\Concerns;

use App\Modules\Sites\Models\Site;
use Illuminate\Validation\Rule;

/**
 * Form fields for the encrypted pull_config of a website: the LeadsHub agent
 * (hub-agent.php) or direct access to its leads table. Secrets are never
 * sent back to the browser; an empty secret field keeps the stored one.
 */
trait EditsPullConfig
{
    /** "agent" or "database". */
    public string $pullDriver = 'agent';

    public string $agentUrl = '';

    public string $agentKey = '';

    public string $agentSecret = '';

    public string $dbHost = '';

    public string $dbPort = '3306';

    public string $dbName = '';

    public string $dbUser = '';

    public string $dbPassword = '';

    public string $dbTable = 'leads';

    protected function fillPullConfig(Site $site): void
    {
        $config = $site->pull_config ?? [];

        $this->pullDriver = ($config['driver'] ?? 'agent') === 'database' ? 'database' : 'agent';
        $this->agentUrl = (string) ($config['agent']['url'] ?? '');
        $this->agentKey = (string) ($config['agent']['key'] ?? '');
        $this->dbHost = (string) ($config['database']['host'] ?? '');
        $this->dbPort = (string) ($config['database']['port'] ?? '3306');
        $this->dbName = (string) ($config['database']['database'] ?? '');
        $this->dbUser = (string) ($config['database']['username'] ?? '');
        $this->dbTable = (string) ($config['database']['table'] ?? 'leads');
        $this->agentSecret = '';
        $this->dbPassword = '';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function validatedPullConfig(Site $site): ?array
    {
        $required = $site->isPull() ? 'required' : 'nullable';
        $agent = $this->pullDriver === 'agent';
        $stored = $site->pull_config ?? [];

        $this->validate([
            'pullDriver' => ['required', Rule::in(['agent', 'database'])],
            'agentUrl' => ['nullable', 'url:http,https', 'max:255'],
            'agentKey' => [$agent ? $required : 'nullable', 'string', 'max:190'],
            'agentSecret' => [$agent && blank($stored['agent']['secret'] ?? null) ? $required : 'nullable', 'string', 'max:255'],
            'dbHost' => [$agent ? 'nullable' : $required, 'string', 'max:190'],
            'dbPort' => ['nullable', 'integer', 'between:1,65535'],
            'dbName' => [$agent ? 'nullable' : $required, 'string', 'max:64'],
            'dbUser' => [$agent ? 'nullable' : $required, 'string', 'max:64'],
            'dbPassword' => ['nullable', 'string', 'max:255'],
            'dbTable' => ['nullable', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
        ], attributes: [
            'agentUrl' => 'agent address', 'agentKey' => 'API key', 'agentSecret' => 'API secret',
            'dbHost' => 'database host', 'dbPort' => 'port', 'dbName' => 'database name', 'dbUser' => 'database user',
            'dbPassword' => 'database password', 'dbTable' => 'table name',
        ]);

        $config = ['driver' => $this->pullDriver];

        if (filled($this->agentKey)) {
            $config['agent'] = [
                'url' => trim($this->agentUrl),
                'key' => trim($this->agentKey),
                'secret' => filled($this->agentSecret) ? $this->agentSecret : (string) ($stored['agent']['secret'] ?? ''),
            ];
        }

        if (filled($this->dbHost)) {
            $config['database'] = [
                'host' => trim($this->dbHost),
                'port' => (int) ($this->dbPort ?: 3306),
                'database' => trim($this->dbName),
                'username' => trim($this->dbUser),
                'password' => filled($this->dbPassword) ? $this->dbPassword : (string) ($stored['database']['password'] ?? ''),
                'table' => trim($this->dbTable) ?: 'leads',
            ];
        }

        $this->agentSecret = '';
        $this->dbPassword = '';

        return isset($config['agent']) || isset($config['database']) ? $config : null;
    }
}
