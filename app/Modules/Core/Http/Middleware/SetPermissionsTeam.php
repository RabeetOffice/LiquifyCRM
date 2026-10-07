<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Brands\Models\Brand;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after Filament has identified the tenant: points spatie's team id at
 * the current brand so permission checks are answered for that brand.
 */
final class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        setPermissionsTeamId($tenant instanceof Brand ? $tenant->getKey() : null);

        return $next($request);
    }
}
