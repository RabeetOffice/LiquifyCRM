<?php

declare(strict_types=1);

namespace App\Modules\Core;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Contracts\PushTransport;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Jobs\SendWebPush;
use App\Modules\Core\Models\PushSubscription;
use App\Modules\Core\Navigation\Menu;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Core\Support\Live;
use App\Modules\Core\Support\RunSoon;
use App\Modules\Core\Support\WebPush;
use App\Modules\Core\Support\WebPushTransport;
use App\Modules\Leads\Events\LeadAssigned;
use App\Modules\Leads\Events\LeadCreated;
use App\Modules\Leads\Events\LeadStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewInstance;

final class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Core';

    public function register(): void
    {
        $this->app->singleton(CurrentBrand::class);
        $this->app->bind(PushTransport::class, WebPushTransport::class);
    }

    protected function bootModule(): void
    {
        Date::use(CarbonImmutable::class);

        // Forwarded headers are trusted only where the configuration says so.
        $proxies = (string) config('liquify.trusted_proxies');

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Fail loudly on N+1 queries and silently discarded attributes while
        // building; production only logs so a stray query never breaks a page.
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());

        // Dashboard numbers are cached for 60 seconds; anything that changes them clears the brand's cache.
        Event::listen([LeadCreated::class, LeadAssigned::class, LeadStatusChanged::class], function (LeadCreated|LeadAssigned|LeadStatusChanged $event): void {
            DashboardCache::flush((int) $event->lead->brand_id);
        });

        // Live updates: a new bell entry moves its person's stamp, and stamps that changed
        // more than once in a second are written when the request or the job ends.
        DatabaseNotification::created(function (DatabaseNotification $notification): void {
            if ($notification->getAttribute('notifiable_type') === (new User)->getMorphClass()) {
                $userId = (int) $notification->getAttribute('notifiable_id');

                Live::touchUser($userId);

                // Desktop alerts with LiquifyCRM closed, for the browsers where this person turned them on.
                if (WebPush::wanted($userId)) {
                    RunSoon::dispatch(new SendWebPush((string) $notification->getKey()));
                }
            }
        });

        // Signing out stops this browser's desktop alerts; the next person to sign in there turns them on for themselves.
        Event::listen(Logout::class, function (Logout $event): void {
            $hash = session()->pull('push_endpoint');

            if (is_string($hash) && $event->user !== null && WebPush::ready()) {
                PushSubscription::query()->where('user_id', $event->user->getAuthIdentifier())->where('endpoint_hash', $hash)->delete();
            }
        });

        $this->app->terminating(fn () => Live::flush());
        Queue::after(fn () => Live::flush());
        Queue::failing(fn () => Live::flush());

        // The shell (sidebar and top bar) knows who is signed in, which brand
        // is open and which menu that person gets.
        View::composer(['partials.sidebar', 'partials.topbar'], function (ViewInstance $view): void {
            $user = auth()->user();
            $current = app(CurrentBrand::class);
            $brand = $current->get();
            $route = (string) Route::currentRouteName();

            // Outside any brand (the profile), a Manager or Agent keeps the menu of the brand they work in,
            // counted within that brand; Admins get the admin area's menu.
            if ($brand === null && $user instanceof User && ($home = Menu::homeBrand($user)) !== null) {
                $view->with(['user' => $user, 'brand' => $home, 'menu' => $current->within($home, fn (): array => Menu::for($user, $home, $route))]);

                return;
            }

            $view->with([
                'user' => $user instanceof User ? $user : null,
                'brand' => $brand,
                'menu' => $user instanceof User ? Menu::for($user, $brand, $route) : [],
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
