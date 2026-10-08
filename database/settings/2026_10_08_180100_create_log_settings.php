<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Defaults from the "Where logs live and how long" table.
        $this->migrator->add('logs.database_days', 30);
        $this->migrator->add('logs.archive_days', 180);
    }

    public function down(): void
    {
        $this->migrator->delete('logs.database_days');
        $this->migrator->delete('logs.archive_days');
    }
};
