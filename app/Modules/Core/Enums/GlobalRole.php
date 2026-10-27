<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

enum GlobalRole: string implements HasColor, HasLabel
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    /** IT (user request, 27 Oct 2026): People, passwords, two-step and System; no leads or sales. */
    case It = 'it';
    /** Website leads (user request, 27 Oct 2026): reads the website leads of the brands chosen for them, nothing else. */
    case WebsiteLeads = 'website_leads';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::It => 'IT',
            self::WebsiteLeads => 'Website leads',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'primary',
            self::Admin => 'info',
            self::It => 'warning',
            self::WebsiteLeads => 'success',
        };
    }
}
