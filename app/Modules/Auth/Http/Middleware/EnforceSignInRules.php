<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Enums\SignInRefusal;
use App\Modules\Auth\Models\SignInDevice;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\SignInGate;
use App\Modules\Auth\Support\SignInRules;
use App\Modules\Core\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in security on every page and every screen request (web group): a
 * blocked address sees a refusal instead of LiquifyCRM, and a signed-in
 * person whom the rules no longer let in (they left the office, a phone was
 * blocked, an Admin changed their rule) is signed out with the reason. Each
 * browser also gets its own random name (lq_device), so a phone is known
 * again and can be approved. Machine endpoints and the public pages pass.
 */
final class EnforceSignInRules
{
    public function __construct(private readonly SignInGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! SignInRules::enforced() || $request->is('webhooks/*', 'api/*', 'hooks/*', 'up', 'privacy', 'data-deletion')) {
            return $next($request);
        }

        $this->nameBrowser($request);

        $user = $request->user();

        if ($user instanceof User) {
            $refusal = $this->gate->enforce($user, $request, SignInGate::deviceOf($request), signingIn: false);

            if ($refusal !== null) {
                return $this->signOut($request, $refusal);
            }
        } elseif (SignInRules::current()->isBlocked($request->ip())) {
            return $this->turnAway($request);
        }

        return $next($request);
    }

    /** A browser seen for the first time gets its name now, and this request already carries it. */
    private function nameBrowser(Request $request): void
    {
        if (SignInGate::tokenOf($request) !== null) {
            return;
        }

        $token = Str::random(48);
        $request->cookies->set(SignInDevice::COOKIE, $token);
        Cookie::queue(Cookie::make(SignInDevice::COOKIE, $token, 60 * 24 * 365 * 5, httpOnly: true, sameSite: 'lax'));
    }

    private function signOut(Request $request, SignInRefusal $refusal): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Kept (not flashed) until the next sign-in: a screen request follows the redirect itself before the browser opens it.
        $request->session()->put(SignInGate::SESSION_REFUSED, $refusal->message());

        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => $refusal->message()], 401);
        }

        return redirect()->route('login');
    }

    private function turnAway(Request $request): Response
    {
        // One line in the log per address every ten minutes, however often it knocks.
        if (Cache::add('sign-in-blocked:'.$request->ip(), true, 600)) {
            Audit::log('auth.address_blocked', null, ['path' => '/'.ltrim($request->path(), '/')], 'Blocked address turned away', logName: 'auth');
        }

        return response()->view('errors.page', [
            'code' => 403,
            'title' => 'This connection is blocked',
            'text' => SignInRefusal::AddressBlocked->message(),
        ], 403);
    }
}
