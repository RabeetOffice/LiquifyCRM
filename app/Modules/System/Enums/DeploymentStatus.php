<?php

declare(strict_types=1);

namespace App\Modules\System\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasIcon;
use App\Modules\Core\Contracts\HasLabel;

enum DeploymentStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Running => 'Running',
            self::Success => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'info',
            self::Success => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Running => 'heroicon-o-arrow-path',
            self::Success => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Success, self::Failed], true);
    }
}
