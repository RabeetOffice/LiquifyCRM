<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * Permission names, one per row of the Permissions table in the architecture
 * document. Super Admin and Admin are global roles resolved in Gate::before;
 * Manager, Agent and Viewer receive these names per brand through spatie
 * teams (team = brand). Row scope (own department, own leads) lives in the
 * Policies, never here.
 */
enum Permission: string
{
    case LeadsView = 'leads.view';
    case LeadsDistribute = 'leads.distribute';
    case LeadsAssign = 'leads.assign';
    case LeadsUpdate = 'leads.update';
    case LeadsRevealContact = 'leads.reveal_contact';
    case LeadsImport = 'leads.import';
    case LeadsExport = 'leads.export';
    case LeadsDelete = 'leads.delete';
    case SalesRecord = 'sales.record';
    case SalesView = 'sales.view';
    case TargetsSet = 'targets.set';
    case UsersManage = 'users.manage';
    case DepartmentsManage = 'departments.manage';
    case BrandsView = 'brands.view';
    case BrandsManage = 'brands.manage';
    case SitesManage = 'sites.manage';
    case MetaManage = 'meta.manage';
    case LogsView = 'logs.view';
    case LogsDownload = 'logs.download';
    case LogsDelete = 'logs.delete';
    case SystemManage = 'system.manage';

    /**
     * Permissions an Admin does not have; only a Super Admin does.
     *
     * @return list<self>
     */
    public static function superAdminOnly(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
