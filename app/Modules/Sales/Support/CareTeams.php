<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\DepartmentKind;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Upsell departments of a brand and the people in them: the choices
 * offered wherever a client is handed over or given to somebody. A choice is
 * written "12" (the department decides) or "12:34" (that person in it).
 */
final class CareTeams
{
    /**
     * @param  list<int>|null  $only  limit to these departments (a Manager's own)
     * @return list<array{department: Department, people: array<int, string>}>
     */
    public static function options(Brand $brand, ?array $only = null): array
    {
        if (! ClientCare::ready()) {
            return [];
        }

        return Department::query()
            ->forBrand($brand)
            ->where('kind', DepartmentKind::Upsell->value)
            ->when($only !== null, fn (Builder $query): Builder => $query->whereIn('id', $only ?? []))
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => [
                'department' => $department,
                'people' => self::people($department)->pluck('name', 'id')->all(),
            ])
            ->all();
    }

    /** Is this person in an Upsell department of the brand (as its Manager or one of its people)? */
    public static function includes(User $user, Brand|int $brand): bool
    {
        if (! ClientCare::ready()) {
            return false;
        }

        return $user->departments()
            ->acrossBrands()
            ->where('departments.brand_id', $brand instanceof Brand ? $brand->getKey() : $brand)
            ->where('departments.kind', DepartmentKind::Upsell->value)
            ->exists();
    }

    /**
     * Someone whose every department in the brand is an Upsell department:
     * they work from clients, and leads are not their business.
     */
    public static function onlyHome(User $user, Brand|int $brand): bool
    {
        if (! ClientCare::ready()) {
            return false;
        }

        $kinds = $user->departments()
            ->acrossBrands()
            ->where('departments.brand_id', $brand instanceof Brand ? $brand->getKey() : $brand)
            ->pluck('departments.kind')
            ->unique();

        return $kinds->count() === 1 && $kinds->first() === DepartmentKind::Upsell->value;
    }

    /**
     * Does this person work from clients alone? True for someone on Upsell
     * teams only who has no lead to look at. Their home is the Clients
     * screen, and the lead screens stay out of their menu.
     */
    public static function worksClientsOnly(User $user, Brand $brand): bool
    {
        return ! $user->isGlobalAdmin()
            && self::onlyHome($user, $brand)
            && ! Lead::query()->forBrand($brand)->visibleTo($user)->exists();
    }

    /**
     * Everyone active in a department, Managers and Agents, by name.
     *
     * @return Collection<int, User>
     */
    public static function people(Department $department): Collection
    {
        return $department->users()
            ->where('users.status', MembershipStatus::Active->value)
            ->whereHas('memberships', fn (Builder $query): Builder => $query
                ->withoutGlobalScopes()
                ->where('brand_id', $department->brand_id)
                ->where('status', MembershipStatus::Active->value))
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Turn a choice back into the department and the person.
     *
     * @return array{0: Department, 1: ?User}|null null when the choice is not an Upsell team of this brand
     */
    public static function parse(Brand $brand, string $choice): ?array
    {
        if (! preg_match('/^(\d+)(?::(\d+))?$/', $choice, $parts)) {
            return null;
        }

        $department = Department::query()->forBrand($brand)->where('kind', DepartmentKind::Upsell->value)->find((int) $parts[1]);

        if ($department === null) {
            return null;
        }

        if (! isset($parts[2])) {
            return [$department, null];
        }

        $person = self::people($department)->firstWhere('id', (int) $parts[2]);

        return $person !== null ? [$department, $person] : null;
    }
}
