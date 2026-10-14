<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Empty until the first page asks for the key (PushKeys makes the pair then).
        $this->migrator->add('push.public_key', null);
        $this->migrator->addEncrypted('push.private_key', null);
    }

    public function down(): void
    {
        $this->migrator->delete('push.public_key');
        $this->migrator->delete('push.private_key');
    }
};
