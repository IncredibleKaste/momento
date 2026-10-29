<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ProtectSecurityCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $management = $request->routeIs('security.edit', 'password.confirm*') || ($request->routeIs('two-factor.*') && ! $request->routeIs('two-factor.login*'))
            || ($request->routeIs('passkey.*') && ! $request->routeIs('passkey.login*'));
        $challenge = $request->routeIs('two-factor.login.store');

        if (! $management && ! $challenge) {
            return $next($request);
        }

        $userId = $management ? $request->user()?->getAuthIdentifier() : $request->session()->get('login.id');

        if ($userId === null) {
            return $next($request);
        }

        if (! is_int($userId) && ! is_string($userId)) {
            abort(403);
        }

        if ($request->routeIs('two-factor.confirm')) {
            $request->validateWithBag('confirmTwoFactorAuthentication', ['code' => ['required', 'string', 'digits:6']]);
        }

        return DB::transaction(function () use ($request, $next, $userId, $management): Response {
            $user = User::query()->lockForUpdate()->find($userId);

            if ($management) {
                abort_unless($user && filled($user->getAuthPassword()) && $user->disabled_at === null, 403);
                $request->user()->refresh();
            } elseif (! $user || $user->disabled_at || blank($user->getAuthPassword()) || ! $user->hasEnabledTwoFactorAuthentication()) {
                return app(FailedTwoFactorLoginResponse::class)->toResponse($request);
            }

            return $next($request);
        });
    }
}
