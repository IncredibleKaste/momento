<?php

use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

test('a future-window authenticator code cannot be reused during its remaining acceptance window', function (int $delay) {
    $this->travelTo(Carbon::createFromTimestampUTC(1800000001));
    $engine = twoFactorTestClock();
    $secret = 'JBSWY3DPEHPK3PXP';
    $code = $engine->oathTotp($secret, $engine->getTimestamp() + 1);
    $provider = app(TwoFactorAuthenticationProvider::class);

    expect($provider->verify($secret, $code))->toBeTrue();
    $this->travel($delay)->seconds();
    expect($provider->verify($secret, $code))->toBeFalse();
})->with([0, 61]);

test('two authenticator secrets with the same numeric code remain independent', function () {
    $this->travelTo(Carbon::createFromTimestampUTC(1800000001));
    twoFactorTestClock();
    $provider = app(TwoFactorAuthenticationProvider::class);

    // Synthetic secrets collide at this fixed timestamp.
    expect($provider->verify('AAAAAAAAAAAAAASX', '412922'))->toBeTrue();
    expect($provider->verify('AAAAAAAAAAAAABCD', '412922'))->toBeTrue();
});
