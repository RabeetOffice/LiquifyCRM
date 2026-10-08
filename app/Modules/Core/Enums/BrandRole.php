<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Contracts\HasColor;
use App\Modules\Core\Contracts\HasLabel;

enum BrandRole: string implements HasColor, HasLabel
{
    case Manager = 'manager';
    case Agent = 'agent';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::Agent => 'Agent',
            self::Viewer => 'Viewer',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manager => 'info',
            self::Agent => 'success',
            self::Viewer => 'gray',
        };
    }

    /**
     * Permission names granted to this brand role (spatie, team = brand).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Manager => [
                Permission::LeadsView,
                Permission::LeadsAssign,
                Permission::LeadsUpdate,
                Permission::LeadsRevealContact,
                Permission::LeadsExport,
                Permission::SalesRecord,
                Permission::SalesView,
                Permission::TargetsSet,
                Permission::UsersManage,
                Permission::DepartmentsManage,
                Permission::BrandsView,
                Permission::LogsView,
                Permission::LogsDownload,
            ],
            self::Agent => [
                Permission::LeadsView,
                Permission::LeadsUpdate,
                Permission::SalesRecord,
                Permission::SalesView,
            ],
            self::Viewer => [
                Permission::SalesView,
            ],
        };
    }

    /**
     * Roles that may be chosen when inviting or creating a member in the MVP.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Manager, self::Agent];
    }
}
