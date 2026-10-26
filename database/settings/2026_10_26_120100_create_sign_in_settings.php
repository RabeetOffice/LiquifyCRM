<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // As before the update: anyone may sign in from anywhere and on any phone, until an Admin chooses otherwise.
        $this->migrator->add('sign_in.network', 'anywhere');
        $this->migrator->add('sign_in.phones', 'any');
    }

    public function down(): void
    {
        $this->migrator->delete('sign_in.network');
        $this->migrator->delete('sign_in.phones');
    }
};
