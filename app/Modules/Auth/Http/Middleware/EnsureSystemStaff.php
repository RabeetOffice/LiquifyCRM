<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** People, two-step, Sign-in security, Logs, Updates and System: Super Admins, Admins and IT. */
final class EnsureSystemStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canManageSystem(), 403);

        return $next($request);
    }
}
