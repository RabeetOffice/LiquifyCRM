<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureGlobalAdmin;
use App\Modules\Auth\Http\Middleware\EnsureGlobalAdminHasTwoFactor;
use App\Modules\Auth\Http\Middleware\EnsureSuperAdmin;
use App\Modules\Core\Http\Middleware\EnsureBrandMember;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Machine endpoints are token or signature authenticated, never session based.
        $middleware->validateCsrfTokens(except: ['hooks/deploy/*', 'webhooks/*', 'api/*']);

        // Behind a tunnel or proxy (ngrok, Cloudflare) the visitor's https address
        // arrives in forwarded headers. Trust them only where TRUSTED_PROXIES says so.
        if (filled(env('TRUSTED_PROXIES'))) {
            $middleware->trustProxies(at: env('TRUSTED_PROXIES') === '*' ? '*' : array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));
        }

        $middleware->alias([
            'global-admin' => EnsureGlobalAdmin::class,
            'super-admin' => EnsureSuperAdmin::class,
            'two-factor' => EnsureGlobalAdminHasTwoFactor::class,
            'brand.member' => EnsureBrandMember::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
