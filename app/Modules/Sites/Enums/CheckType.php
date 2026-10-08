<?php

declare(strict_types=1);

namespace App\Modules\Sites\Enums;

use App\Modules\Core\Contracts\HasIcon;
use App\Modules\Core\Contracts\HasLabel;

/** The five health checks carried over from LeadsHub / SiteWatch. */
enum CheckType: string implements HasIcon, HasLabel
{
    case Form = 'form';
    case Smtp = 'smtp';
    case Database = 'database';
    case Ssl = 'ssl';
    case Uptime = 'uptime';

    public function getLabel(): string
    {
        return match ($this) {
            self::Form => 'Form',
            self::Smtp => 'Email',
            self::Database => 'Database',
            self::Ssl => 'SSL certificate',
            self::Uptime => 'Website is up',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Form => 'heroicon-o-document-text',
            self::Smtp => 'heroicon-o-envelope',
            self::Database => 'heroicon-o-circle-stack',
            self::Ssl => 'heroicon-o-lock-closed',
            self::Uptime => 'heroicon-o-signal',
        };
    }
}
