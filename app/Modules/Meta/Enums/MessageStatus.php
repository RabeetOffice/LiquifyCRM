<?php

declare(strict_types=1);

namespace App\Modules\Meta\Enums;

use App\Modules\Core\Contracts\HasLabel;

enum MessageStatus: string implements HasLabel
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sending => 'Sending…',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Seen',
            self::Failed => 'Not sent',
        };
    }
}
