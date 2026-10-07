<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Activity\Models\Activity;
use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * One entry point for the audit trail. Every logged action records who, which
 * brand and department, what happened, properties, IP address and browser.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        string $description = '',
        Brand|int|null $brand = null,
        Department|int|null $department = null,
        ?Model $causer = null,
        string $logName = 'default',
    ): Activity {
        // Read raw attributes so strict mode never throws for a subject that
        // simply has no brand_id or department_id column (a User, for example).
        $subjectAttributes = $subject?->getAttributes() ?? [];

        $brandId = match (true) {
            $brand instanceof Brand => $brand->getKey(),
            is_int($brand) => $brand,
            ($subjectAttributes['brand_id'] ?? null) !== null => $subjectAttributes['brand_id'],
            default => app(CurrentBrand::class)->id(),
        };

        $departmentId = match (true) {
            $department instanceof Department => $department->getKey(),
            is_int($department) => $department,
            $subject instanceof Department => $subject->getKey(),
            ($subjectAttributes['department_id'] ?? null) !== null => $subjectAttributes['department_id'],
            default => null,
        };

        $request = app()->runningInConsole() ? null : request();

        $logger = activity($logName)
            ->event($event)
            ->withProperties($properties)
            ->tap(function (Activity $activity) use ($brandId, $departmentId, $request): void {
                $activity->brand_id = $brandId === null ? null : (int) $brandId;
                $activity->department_id = $departmentId === null ? null : (int) $departmentId;
                $activity->ip = $request?->ip();
                $activity->user_agent = $request ? mb_substr((string) $request->userAgent(), 0, 512) : null;
            });

        $causer ??= Auth::user();

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        /** @var Activity $activity */
        $activity = $logger->log($description !== '' ? $description : $event);

        return $activity;
    }
}
