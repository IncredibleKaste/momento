<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkeys;
use Symfony\Component\HttpFoundation\Response;

class ProtectPasskeyCeremonies
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $purpose = match ($request->route()?->getName()) {
            'passkey.registration-options', 'passkey.store' => 'registration',
            'passkey.login-options', 'passkey.login' => 'login',
            'passkey.confirm-options', 'passkey.confirm' => 'confirmation',
            default => null,
        };

        if ($purpose === null) {
            return $next($request);
        }

        $optionsKey = $purpose === 'registration' ? 'passkey.registration_options' : 'passkey.verification_options';
        $stateKey = 'passkey.ceremonies.'.$purpose;

        if ($request->isMethod('GET')) {
            $response = $next($request);
            $options = $request->session()->get($optionsKey);

            if ($response->isSuccessful() && is_string($options)) {
                $request->session()->put($stateKey, [
                    'options' => hash('sha256', $options),
                    'owner' => $request->user()?->getAuthIdentifier(),
                    'expires_at' => now()->addMilliseconds(Passkeys::timeout())->timestamp,
                ]);
            }

            return $response;
        }

        $state = $request->session()->pull($stateKey);
        $options = $request->session()->get($optionsKey);

        if (! is_array($state) || ! is_string($options)
            || ($state['owner'] ?? null) !== $request->user()?->getAuthIdentifier()
            || ($state['expires_at'] ?? 0) <= now()->timestamp
            || ($state['options'] ?? null) !== hash('sha256', $options)
            || ! Cache::add('momento.passkey.'.hash('sha256', $options), true, $state['expires_at'] - now()->timestamp + 1)) {
            throw ValidationException::withMessages([
                'credential' => __('This passkey request expired or was already used. Please try again.'),
            ]);
        }

        return $next($request);
    }
}
