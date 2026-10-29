<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\TwoFactorAuthenticationProvider as BaseProvider;

class TwoFactorAuthenticationProvider extends BaseProvider
{
    public function verify(#[\SensitiveParameter] $secret, #[\SensitiveParameter] $code): bool
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }

        if (is_int($window = config('fortify-options.two-factor-authentication.window'))) {
            $this->engine->setWindow($window);
        }

        $timestamp = $this->engine->verifyKeyNewer($secret, $code, 0);

        if (! is_int($timestamp)) {
            return false;
        }

        // Keep the atomic marker until every accepted clock-skew window has closed.
        $lifetime = (2 * $this->engine->getWindow() + 1) * $this->engine->getKeyRegeneration();

        return $this->cache?->add('momento.2fa.'.hash('sha256', $secret).'.'.$timestamp, true, $lifetime) ?? false;
    }
}
