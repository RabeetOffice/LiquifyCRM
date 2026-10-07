<?php

declare(strict_types=1);

namespace App\Modules\Core;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Base provider for every module under app/Modules. A module registers its
 * own migrations, views, routes, policies and commands so it can be added or
 * removed without touching the others.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Folder name under app/Modules, e.g. "Leads". */
    protected string $module;

    /** @var array<class-string, class-string> model => policy */
    protected array $policies = [];

    /** @var list<class-string> */
    protected array $commands = [];

    public function boot(): void
    {
        $path = $this->path();

        if (is_dir($path.'/Database/Migrations')) {
            $this->loadMigrationsFrom($path.'/Database/Migrations');
        }

        if (is_dir($path.'/resources/views')) {
            $this->loadViewsFrom($path.'/resources/views', $this->viewNamespace());
        }

        if (is_dir($path.'/resources/views/components')) {
            Blade::anonymousComponentPath($path.'/resources/views/components', $this->viewNamespace());
        }

        if (is_file($path.'/routes/web.php')) {
            Route::middleware('web')->group($path.'/routes/web.php');
        }

        if (is_file($path.'/routes/api.php')) {
            Route::middleware('api')->prefix('api')->group($path.'/routes/api.php');
        }

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        if ($this->app->runningInConsole() && $this->commands !== []) {
            $this->commands($this->commands);
        }

        $this->bootModule();
    }

    protected function bootModule(): void
    {
        //
    }

    protected function path(): string
    {
        return app_path('Modules/'.$this->module);
    }

    protected function viewNamespace(): string
    {
        return Str::kebab($this->module);
    }
}
