<?php

declare(strict_types=1);

namespace App\Modules\Core;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Support\CurrentBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

final class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Core';

    public function register(): void
    {
        $this->app->singleton(CurrentBrand::class);
    }

    protected function bootModule(): void
    {
        Date::use(CarbonImmutable::class);

        // Fail loudly on N+1 queries and silently discarded attributes while
        // building; production only logs so a stray query never breaks a page.
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());

        // Layer 3 of access control. Global roles short-circuit permission-name
        // checks only; model abilities (view, update, delete, ...) return null
        // here so every Policy still runs, including Super Admin protection.
        Gate::before(function (User $user, string $ability): ?bool {
            if (Permission::tryFrom($ability) === null) {
                return null;
            }

            if ($user->isSuspended()) {
                return false;
            }

            if ($user->isSuperAdmin()) {
                return true;
            }

            if ($user->isAdmin()) {
                return ! in_array(Permission::from($ability), Permission::superAdminOnly(), true);
            }

            return null;
        });
    }
}
