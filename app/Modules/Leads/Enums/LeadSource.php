<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasIcon;
use App\Modules\Core\Contracts\HasLabel;

enum LeadSource: string implements HasColor, HasIcon, HasLabel
{
    case Website = 'website';
    case Facebook = 'facebook';
    case Messenger = 'messenger';
    case Instagram = 'instagram';
    case Manual = 'manual';
    case Import = 'import';
    case Api = 'api';

    public function getLabel(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Facebook => 'Facebook',
            self::Messenger => 'Messenger',
            self::Instagram => 'Instagram',
            self::Manual => 'Added by hand',
            self::Import => 'Imported',
            self::Api => 'API',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Website => 'info',
            self::Facebook, self::Messenger, self::Instagram => 'primary',
            self::Manual => 'gray',
            self::Import => 'warning',
            self::Api => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Website => 'heroicon-o-globe-alt',
            self::Facebook => 'heroicon-o-megaphone',
            self::Messenger => 'heroicon-o-chat-bubble-left-right',
            self::Instagram => 'heroicon-o-camera',
            self::Manual => 'heroicon-o-pencil-square',
            self::Import => 'heroicon-o-arrow-up-tray',
            self::Api => 'heroicon-o-bolt',
        };
    }
}
