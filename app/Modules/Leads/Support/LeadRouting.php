<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\LeadRoute;
use App\Modules\Sales\Support\ClientCare;

/**
 * Reads the rule of a lead's source: the department its leads go to and,
 * when one was chosen, the person. A person who left the brand or the
 * department no longer receives leads; the department still does.
 */
final class LeadRouting
{
    /** @return array{department: Department, user: ?User}|null */
    public static function resolve(Brand $brand, LeadSource $source, mixed $sourceId): ?array
    {
        $channel = LeadRoute::channelOf($source);

        if ($channel === null || ! is_numeric($sourceId) || ! ClientCare::ready()) {
            return null;
        }

        $route = LeadRoute::query()->forBrand($brand)->where('channel', $channel)->where('source_id', (int) $sourceId)->first();
        $department = $route?->department_id !== null ? Department::query()->forBrand($brand)->find($route->department_id) : null;

        if ($route === null || $department === null) {
            return null;
        }

        $user = $route->user_id !== null ? User::query()->find($route->user_id) : null;

        if ($user !== null && (! $user->isActive() || ! $user->isMemberOf($brand) || ! $department->hasMember($user))) {
            $user = null;
        }

        return ['department' => $department, 'user' => $user];
    }
}
