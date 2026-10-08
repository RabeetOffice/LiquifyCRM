<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\CurrentBrand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 1 of brand isolation on /b/{brand}: the brand in the URL must be
 * one the signed-in person belongs to (global admins belong everywhere),
 * otherwise the page does not exist for them. Also points spatie's team
 * id at the brand so permission checks are answered for that brand.
 */
final class EnsureBrandMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $brand = $request->route('brand');
        $user = $request->user();

        if (! $brand instanceof Brand || ! $user instanceof User || ! $brand->is_active) {
            abort(404);
        }

        // A suspended person, or a suspended membership, is told so; a person
        // who was never a member must not learn the brand exists.
        if (! $user->canAccessBrand($brand)) {
            $knownMember = $user->isGlobalAdmin() || $user->membershipFor($brand) !== null;

            abort($knownMember ? 403 : 404);
        }

        app(CurrentBrand::class)->set($brand);

        return $next($request);
    }
}
