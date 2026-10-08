<div class="lq-form-grid lq-form-grid-2">
    <x-ui.field label="Read through" name="pullDriver" class="lq-col-full">
        <x-ui.select wire:model.live="pullDriver" name="pullDriver">
            <option value="agent">The health agent on the website (hub-agent.php from LeadsHub)</option>
            <option value="database">The website's database directly</option>
        </x-ui.select>
    </x-ui.field>

    @if ($pullDriver === 'agent')
        <x-ui.field label="Agent address" name="agentUrl" help="Leave empty for {{ $site->pageUrl('/hub-agent.php') }}">
            <x-ui.input wire:model="agentUrl" name="agentUrl" placeholder="{{ $site->pageUrl('/hub-agent.php') }}" />
        </x-ui.field>
        <x-ui.field label="API key" name="agentKey" help="From the website's hub-agent-config.php.">
            <x-ui.input wire:model="agentKey" name="agentKey" autocomplete="off" />
        </x-ui.field>
        <x-ui.field label="API secret" name="agentSecret" :help="$site->agentConfig() ? 'Saved. Leave empty to keep it.' : null">
            <x-ui.input type="password" wire:model="agentSecret" name="agentSecret" autocomplete="new-password" />
        </x-ui.field>
    @else
        <x-ui.field label="Database host" name="dbHost" help="The website's hosting must allow remote MySQL from this server.">
            <x-ui.input wire:model="dbHost" name="dbHost" placeholder="srv123.hstgr.io" />
        </x-ui.field>
        <x-ui.field label="Port" name="dbPort">
            <x-ui.input wire:model="dbPort" name="dbPort" inputmode="numeric" />
        </x-ui.field>
        <x-ui.field label="Database name" name="dbName">
            <x-ui.input wire:model="dbName" name="dbName" />
        </x-ui.field>
        <x-ui.field label="Leads table" name="dbTable">
            <x-ui.input wire:model="dbTable" name="dbTable" placeholder="leads" />
        </x-ui.field>
        <x-ui.field label="Database user" name="dbUser" help="A read-only user is enough.">
            <x-ui.input wire:model="dbUser" name="dbUser" autocomplete="off" />
        </x-ui.field>
        <x-ui.field label="Database password" name="dbPassword" :help="$site->databaseConfig() ? 'Saved. Leave empty to keep it.' : null">
            <x-ui.input type="password" wire:model="dbPassword" name="dbPassword" autocomplete="new-password" />
        </x-ui.field>
    @endif
</div>
