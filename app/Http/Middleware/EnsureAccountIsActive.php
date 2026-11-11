<?php

namespace App\Http\Middleware;

use App\Models\OidcIdentity;
use App\Services\OidcAuthentication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $identity = blank($user->password) ? OidcIdentity::query()->where('user_id', $user->id)->first() : null;
            if ($user->disabled_at || (blank($user->password) && (! $identity || $identity->disabled_at
                || ! app(OidcAuthentication::class)->providerAllowsIdentity($identity->provider, $identity->issuer, $identity->institution_domain, $identity->stable_subject)))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                Inertia::clearHistory();

                if ($request->expectsJson()) {
                    abort(403);
                }

                Inertia::share('auth.user', null);
                $response = Inertia::render('access-denied')->toResponse($request)->setStatusCode(403);
                $response->headers->set('Cache-Control', 'private, no-store');

                return $response;
            }
        }

        return $next($request);
    }
}
