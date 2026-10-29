<?php

namespace App\Http\Middleware;

use App\Models\MicrosoftIdentity;
use App\Services\MicrosoftOidc;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $identity = blank($user->password) ? MicrosoftIdentity::query()->where('user_id', $user->id)->first() : null;
            if ($user->disabled_at || (blank($user->password) && (! $identity || $identity->disabled_at || ! app(MicrosoftOidc::class)->allowsTenant($identity->tenant_id)))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                abort(403);
            }
        }

        return $next($request);
    }
}
