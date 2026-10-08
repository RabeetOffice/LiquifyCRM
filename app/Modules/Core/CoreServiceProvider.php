<?php

declare(strict_types=1);

namespace App\Modules\Core;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Navigation\Menu;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Leads\Events\LeadAssigned;
use App\Modules\Leads\Events\LeadCreated;
use App\Modules\Leads\Events\LeadStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewInstance;

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

        // Dashboard numbers are cached for 60 seconds; anything that changes them clears the brand's cache.
        Event::listen([LeadCreated::class, LeadAssigned::class, LeadStatusChanged::class], function (LeadCreated|LeadAssigned|LeadStatusChanged $event): void {
            DashboardCache::flush((int) $event->lead->brand_id);
        });

        // The shell (sidebar and top bar) knows who is signed in, which brand
        // is open and which menu that person gets.
        View::composer(['partials.sidebar', 'partials.topbar'], function (ViewInstance $view): void {
            $user = auth()->user();
            $brand = app(CurrentBrand::class)->get();

            $view->with([
                'user' => $user instanceof User ? $user : null,
                'brand' => $brand,
                'menu' => $user instanceof User ? Menu::for($user, $brand, (string) Route::currentRouteName()) : [],
            ]);
        });

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
