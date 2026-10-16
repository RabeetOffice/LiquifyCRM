<div class="lq-form-grid lq-form-grid-2">
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
</div>
