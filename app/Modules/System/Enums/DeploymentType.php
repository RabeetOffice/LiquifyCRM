<?php

declare(strict_types=1);

namespace App\Modules\System\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasIcon;
use App\Modules\Core\Contracts\HasLabel;

enum DeploymentType: string implements HasColor, HasIcon, HasLabel
{
    case Deploy = 'deploy';
    case DatabaseUpdate = 'db_update';
    case Backup = 'backup';
    case Rollback = 'rollback';

    public function getLabel(): string
    {
        return match ($this) {
            self::Deploy => 'Deploy',
            self::DatabaseUpdate => 'Database update',
            self::Backup => 'Backup',
            self::Rollback => 'Rollback',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Deploy => 'primary',
            self::DatabaseUpdate => 'warning',
            self::Backup => 'gray',
            self::Rollback => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Deploy => 'heroicon-o-rocket-launch',
            self::DatabaseUpdate => 'heroicon-o-circle-stack',
            self::Backup => 'heroicon-o-archive-box',
            self::Rollback => 'heroicon-o-arrow-uturn-left',
        };
    }
}
