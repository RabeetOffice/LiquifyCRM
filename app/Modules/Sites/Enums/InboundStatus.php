<?php

declare(strict_types=1);

namespace App\Modules\Sites\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

enum InboundStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Done => 'Received',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }
}
