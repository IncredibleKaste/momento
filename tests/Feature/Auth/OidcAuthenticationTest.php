<?php

use App\Models\OidcIdentity;
use App\Models\User;
use App\Notifications\OidcContactVerification;
use App\Services\OidcAuthentication;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

const OIDC_TEST_CLIENT = 'momento-client@example.test';
const OIDC_SWITCH_DOMAIN = 'institution.example.test';
const OIDC_CONTACT_STATUS = 'If this address is eligible, check its inbox for a verification link. Open it in this browser session; submit the form again if you need a fresh link.';

beforeEach(function () {
    config(['cache.default' => 'array']);
    $this->withoutMiddleware(ThrottleRequests::class);
    config([
        'services.google' => [
            'client_id' => OIDC_TEST_CLIENT,
            'client_secret' => 'test-only-client-secret',
            'redirect_uri' => 'http://localhost/auth/oidc/google/callback',
        ],
        'services.switch' => [
            'client_id' => OIDC_TEST_CLIENT,
            'client_secret' => 'test-only-client-secret',
            'redirect_uri' => 'http://localhost/auth/oidc/switch/callback',
            'environment' => 'test',
            'institutional_domains' => [OIDC_SWITCH_DOMAIN],
        ],
    ]);
});

function oidcHash(string $value): string
{
    $half = substr(hash('sha256', $value, true), 0, 16);

    return rtrim(strtr(base64_encode($half), '+/', '-_'), '=');
}

function signedOidcToken(array $claims, bool $badSignature = false, string $algorithm = 'RS256'): array
{
    static $key;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $payload = $encode(json_encode(['alg' => $algorithm, 'kid' => 'oidc-test-key'])).'.'.$encode(json_encode($claims));
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
    if ($badSignature) {
        $signature[0] = chr(ord($signature[0]) ^ 1);
    }

    return [$payload.'.'.$encode($signature), ['keys' => [[
        'kty' => 'RSA', 'kid' => 'oidc-test-key', 'use' => 'sig', 'alg' => 'RS256',
        'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
    ]]]];
}

function oidcMetadata(string $provider): array
{
    if ($provider === 'google') {
        return [
            'issuer' => 'https://accounts.google.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            'response_types_supported' => ['code'], 'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    return [
        'issuer' => 'https://login.test.eduid.ch/',
        'authorization_endpoint' => 'https://login.test.eduid.ch/idp/profile/oidc/authorize',
        'token_endpoint' => 'https://login.test.eduid.ch/idp/profile/oidc/token',
        'jwks_uri' => 'https://login.test.eduid.ch/idp/profile/oidc/keyset',
        'response_types_supported' => ['code'], 'subject_types_supported' => ['pairwise'],
        'id_token_signing_alg_values_supported' => ['RS256'],
    ];
}

function beginOidc($test, string $provider = 'google', array $changes = [], bool $badSignature = false, string $algorithm = 'RS256'): array
{
    $stub = (object) ['claims' => [], 'requests' => [], 'provider' => $provider, 'badSignature' => $badSignature, 'algorithm' => $algorithm];
    $metadata = oidcMetadata($provider);
    $discovery = $provider === 'google'
        ? 'https://accounts.google.com/.well-known/openid-configuration'
        : 'https://login.test.eduid.ch/.well-known/openid-configuration';
    $http = new Client(['handler' => function ($request) use ($stub, $metadata, $discovery) {
        $stub->requests[] = $request;
        $url = (string) $request->getUri();
        if ($url === $discovery) {
            $body = $metadata;
        } elseif ($url === $metadata['token_endpoint']) {
            [$token] = signedOidcToken($stub->claims, $stub->badSignature, $stub->algorithm);
            $body = ['id_token' => $token, 'access_token' => 'discarded-test-token', 'token_type' => 'Bearer'];
        } elseif ($url === $metadata['jwks_uri']) {
            [, $body] = signedOidcToken($stub->claims, $stub->badSignature, $stub->algorithm);
        } else {
            throw new RuntimeException('Unexpected provider request.');
        }

        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
    }]);
    app()->instance(OidcAuthentication::class, new OidcAuthentication($http));
    $startResponse = $test->get(route('oidc.start', $provider));
    $startResponse->assertRedirect();
    $transaction = session('oidc.transaction');
    expect($transaction)->toBeArray();
    $issuer = $provider === 'google' ? 'https://accounts.google.com' : 'https://login.test.eduid.ch/';
    $stub->claims = [...[
        'iss' => $issuer, 'aud' => OIDC_TEST_CLIENT,
        'iat' => time(), 'nbf' => time() - 1, 'exp' => time() + 300,
        'nonce' => $transaction['nonce'], 'sub' => 'stable-case-sensitive-subject',
        'name' => 'Alex Example', 'email' => 'provider-email-is-not-used@example.test',
        ...($provider === 'switch' ? ['eduid_idp' => OIDC_SWITCH_DOMAIN] : []),
    ], ...$changes];

    return [route('oidc.callback', ['provider' => $provider, 'state' => $transaction['state'], 'code' => 'synthetic-authorization-code']), $stub, $transaction, $startResponse];
}

function oidcContactVerificationUrl(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(OidcContactVerification::class, function ($notification, array $channels, $notifiable) use (&$url, $email): bool {
        $url = $notification->url;

        return $channels === ['mail'] && $notifiable->routeNotificationFor('mail') === $email;
    });

    return $url ?? throw new RuntimeException('No matching contact verification notification was captured.');
}

it('serves login with absent provider configuration and resolves the OIDC transport', function () {
    config(['services.google' => null, 'services.switch' => null]);

    expect(app(OidcAuthentication::class))->toBeInstanceOf(OidcAuthentication::class);
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->component('auth/login')
        ->where('canGoogleLogin', false)->where('canSwitchLogin', false));
});

it('exposes only providers with complete syntactic configuration', function () {
    config(['services.google.client_secret' => null, 'services.switch.institutional_domains' => []]);

    expect(app(OidcAuthentication::class))->toBeInstanceOf(OidcAuthentication::class);
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->component('auth/login')->where('canGoogleLogin', false)->where('canSwitchLogin', false));
    $this->get(route('oidc.start', 'google'))->assertRedirectToRoute('login');
    $this->get(route('oidc.start', 'switch'))->assertRedirectToRoute('login');
});

it('throttles OIDC starts at the route boundary', function () {
    config(['services.google.client_secret' => null]);
    $this->withMiddleware(ThrottleRequests::class)
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.random_int(1, 254)]);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->get(route('oidc.start', 'google'))->assertRedirectToRoute('login');
    }

    $this->get(route('oidc.start', 'google'))->assertTooManyRequests();
});

it('allows valid configured ports only for loopback HTTP in local environments', function () {
    $oidc = app(OidcAuthentication::class);
    foreach ([8000, 8320, 8384, 65535] as $port) {
        config(['services.google.redirect_uri' => "http://localhost:$port/auth/oidc/google/callback"]);
        expect($oidc->providerEnabled('google'))->toBeTrue();
    }

    config(['services.google.redirect_uri' => 'http://login.example.test:8320/auth/oidc/google/callback']);
    expect($oidc->providerEnabled('google'))->toBeFalse();
    config(['services.google.redirect_uri' => 'http://localhost:0/auth/oidc/google/callback']);
    expect($oidc->providerEnabled('google'))->toBeFalse();

    $this->app->detectEnvironment(fn () => 'production');
    config(['services.google.redirect_uri' => 'http://localhost:8384/auth/oidc/google/callback']);
    expect($oidc->providerEnabled('google'))->toBeFalse();
    config(['services.google.redirect_uri' => 'https://login.example.test/auth/oidc/google/callback']);
    expect($oidc->providerEnabled('google'))->toBeTrue();
    $this->app->detectEnvironment(fn () => 'testing');
});

it('binds Google authorization to state, nonce, S256 PKCE, and minimal scopes', function () {
    [, , $transaction, $response] = beginOidc($this);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query)->toMatchArray(['scope' => 'openid profile', 'response_type' => 'code', 'code_challenge_method' => 'S256', 'state' => session('oidc.transaction.state'), 'nonce' => session('oidc.transaction.nonce')]);
    expect($query)->not->toHaveKey('login_hint')->not->toHaveKey('email');
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', session('oidc.transaction.code_verifier'), true)), '+/', '-_'), '='));
    expect($transaction['state'])->toBe(session('oidc.transaction.state'));
});

it('verifies either documented Google issuer spelling before canonical storage and separate contact proof', function (string $issuer) {
    Notification::fake();
    [$callback, $stub, , $startResponse] = beginOidc($this, 'google', ['iss' => $issuer]);
    $guestSession = $startResponse->getCookie(config('session.cookie'))->getValue();

    $this->get($callback)->assertRedirectToRoute('oidc.contact')->assertSessionMissing('oidc.transaction');
    $this->assertGuest();
    expect(User::count())->toBe(0);
    $this->get(route('oidc.contact'))->assertInertia(fn (Assert $page) => $page->component('auth/oidc-contact')->where('suggestedName', 'Alex Example')->missing('subject')->missing('issuer'));
    $this->post(route('oidc.contact.store'), ['name' => 'Alex Example', 'email' => ' Contact@Example.Test '])->assertRedirectToRoute('oidc.contact')->assertSessionHas('status', OIDC_CONTACT_STATUS);
    $url = oidcContactVerificationUrl('contact@example.test');
    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('auth/oidc-contact-confirm')->where('email', 'contact@example.test'));
    $login = $this->post($url)->assertRedirectToRoute('dashboard');
    expect($login->getCookie(config('session.cookie'))->getValue())->not->toBe($guestSession);
    $this->post($url);

    $user = User::sole();
    expect($user->email)->toBe('contact@example.test')->and($user->password)->toBeNull()->and($user->email_verified_at)->not->toBeNull();
    expect(OidcIdentity::sole()->issuer)->toBe('https://accounts.google.com')->and(OidcIdentity::sole()->subject)->toBe('stable-case-sensitive-subject');
    $requestUrls = array_map(fn ($request): string => (string) $request->getUri(), $stub->requests);
    expect($requestUrls)->toContain('https://oauth2.googleapis.com/token');
    foreach ($stub->requests as $outbound) {
        expect((string) $outbound->getBody())->not->toContain('discarded-test-token');
    }
})->with([
    'canonical issuer' => ['https://accounts.google.com'],
    'documented bare issuer' => ['accounts.google.com'],
]);

it('expires pending identity data before contact proof', function () {
    [$callback] = beginOidc($this, 'google');
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $pending = session('oidc.pending');
    $this->withSession(['oidc.pending' => [...$pending, 'expires_at' => now()->subSecond()->timestamp]])
        ->get(route('oidc.contact'))->assertRedirectToRoute('login');

    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('requires the original browser session to complete contact verification', function () {
    Notification::fake();
    [$callback] = beginOidc($this, 'google');
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex Example', 'email' => 'session-proof@example.test'])
        ->assertRedirectToRoute('oidc.contact');
    $url = oidcContactVerificationUrl('session-proof@example.test');

    $this->flushSession();
    $this->get($url)->assertRedirectToRoute('login');
    $this->post($url)->assertRedirectToRoute('login');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('uses the submitted contact address instead of provider email and never links an existing account', function () {
    $existing = User::factory()->create(['email' => 'provider-email-is-not-used@example.test']);
    Notification::fake();
    [$callback] = beginOidc($this, 'google');
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => $existing->email])->assertRedirectToRoute('oidc.contact')->assertSessionHas('status', OIDC_CONTACT_STATUS);

    expect(User::count())->toBe(1)->and(OidcIdentity::count())->toBe(0);
    Notification::assertNothingSent();
});

it('rejects adversarial signed Google claims before creating a pending identity', function (array $changes, bool $badSignature, string $algorithm) {
    [$callback] = beginOidc($this, 'google', $changes, $badSignature, $algorithm);

    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc')->assertSessionMissing('oidc.pending');
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
})->with([
    'wrong issuer' => [['iss' => 'https://issuer.example.test'], false, 'RS256'],
    'wrong audience' => [['aud' => 'other-client'], false, 'RS256'],
    'multiple audiences even with azp' => [['aud' => [OIDC_TEST_CLIENT, 'other-client'], 'azp' => OIDC_TEST_CLIENT], false, 'RS256'],
    'wrong azp' => [['azp' => 'other-client'], false, 'RS256'],
    'wrong nonce' => [['nonce' => 'other-transaction'], false, 'RS256'],
    'expired token' => [['exp' => 1], false, 'RS256'],
    'future token' => [['nbf' => time() + 600], false, 'RS256'],
    'missing subject' => [['sub' => null], false, 'RS256'],
    'wrong signing algorithm' => [[], false, 'HS256'],
    'forged signature' => [[], true, 'RS256'],
    'invalid signed c_hash' => [['c_hash' => 'not-the-code-hash'], false, 'RS256'],
    'invalid signed at_hash' => [['at_hash' => 'not-the-access-token-hash'], false, 'RS256'],
    'invalid signed s_hash' => [['s_hash' => 'not-the-state-hash'], false, 'RS256'],
]);

it('accepts correctly bound signed code, access-token, and state hashes for each provider', function (string $provider) {
    [$callback, $stub, $transaction] = beginOidc($this, $provider);
    $stub->claims['c_hash'] = oidcHash('synthetic-authorization-code');
    $stub->claims['at_hash'] = oidcHash('discarded-test-token');
    $stub->claims['s_hash'] = oidcHash($transaction['state']);

    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
})->with(['Google' => 'google', 'SWITCH' => 'switch']);

it('rejects callback state mismatch and consumes a valid callback once', function () {
    [$callback, $stub] = beginOidc($this, 'google');
    $requestCount = count($stub->requests);
    $this->get(route('oidc.callback', ['provider' => 'google', 'state' => 'wrong', 'code' => 'synthetic-authorization-code']))->assertRedirectToRoute('login');
    expect($stub->requests)->toHaveCount($requestCount);

    [$callback, , $transaction] = beginOidc($this, 'google');
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->withSession(['oidc.transaction' => $transaction])->get($callback)->assertRedirectToRoute('login');

    [$callback, $stub, $transaction] = beginOidc($this, 'google');
    $requestCount = count($stub->requests);
    $this->get(route('oidc.callback', ['provider' => 'switch', 'state' => $transaction['state'], 'code' => 'synthetic-authorization-code']))
        ->assertRedirectToRoute('login');
    expect($stub->requests)->toHaveCount($requestCount)->and(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('requests and validates only the signed allowlisted SWITCH institutional identity', function () {
    Notification::fake();
    [$callback, , , $startResponse] = beginOidc($this, 'switch');
    parse_str(parse_url($startResponse->headers->get('Location'), PHP_URL_QUERY), $query);
    $claimsRequest = json_decode($query['claims'], true, flags: JSON_THROW_ON_ERROR);
    expect($query['scope'])->toBe('openid')
        ->and($claimsRequest['id_token']['eduid_idp'])->toBe(['essential' => true, 'values' => [OIDC_SWITCH_DOMAIN]]);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex Example', 'email' => 'switch-contact@example.test'])->assertRedirectToRoute('oidc.contact');
    $url = oidcContactVerificationUrl('switch-contact@example.test');
    $this->post($url)->assertRedirectToRoute('dashboard');

    expect(OidcIdentity::sole()->provider)->toBe('switch')
        ->and(OidcIdentity::sole()->issuer)->toBe('https://login.test.eduid.ch/')
        ->and(OidcIdentity::sole()->institution_domain)->toBe(OIDC_SWITCH_DOMAIN);
});

it('rejects SWITCH personal, unapproved, missing, and noncanonical signed domains', function (mixed $domain) {
    [$callback] = beginOidc($this, 'switch', ['eduid_idp' => $domain]);

    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc')->assertSessionMissing('oidc.pending');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
})->with([
    'personal identity' => ['eduid.ch'],
    'unapproved institution' => ['other.example.test'],
    'missing claim' => [null],
    'uppercase claim' => ['INSTITUTION.EXAMPLE.TEST'],
]);

it('binds SWITCH tokens to the selected relying party and transaction', function (array $changes) {
    [$callback] = beginOidc($this, 'switch', $changes);

    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc')->assertSessionMissing('oidc.pending');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
})->with([
    'wrong relying party audience' => [['aud' => 'another-client']],
    'additional relying party audience' => [['aud' => [OIDC_TEST_CLIENT, 'another-client'], 'azp' => OIDC_TEST_CLIENT]],
    'wrong authorized party' => [['azp' => 'another-client']],
    'wrong issuer preset' => [['iss' => 'https://login.eduid.ch/']],
    'wrong nonce' => [['nonce' => 'another-transaction']],
    'expired identity token' => [['exp' => 1]],
    'invalid signed c_hash' => [['c_hash' => 'not-the-code-hash']],
    'invalid signed at_hash' => [['at_hash' => 'not-the-access-token-hash']],
    'invalid signed s_hash' => [['s_hash' => 'not-the-state-hash']],
]);

it('invalidates a SWITCH contact flow when its institution is removed from the allowlist', function () {
    [$callback] = beginOidc($this, 'switch');
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->get(route('oidc.contact'))->assertOk();
    config(['services.switch.institutional_domains' => []]);

    $this->get(route('oidc.contact'))->assertRedirectToRoute('login');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('logs in only through the exact active provider identity and expires sessions when its provider is disabled', function () {
    $user = User::factory()->passwordless()->create();
    OidcIdentity::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'issuer' => 'https://accounts.google.com',
        'subject' => 'stable-case-sensitive-subject',
    ]);
    [$callback, , , $startResponse] = beginOidc($this, 'google', ['email' => 'changed-provider-email@example.test']);
    $guestSession = $startResponse->getCookie(config('session.cookie'))->getValue();

    $login = $this->get($callback)->assertRedirectToRoute('dashboard');
    expect($login->getCookie(config('session.cookie'))->getValue())->not->toBe($guestSession);
    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertOk();
    config(['services.google.client_secret' => null]);
    $this->get(route('dashboard'))->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('access-denied'));
    $this->assertGuest();
});

it('denies passwordless and disabled account local credential authentication', function () {
    $passwordless = User::factory()->create(['password' => null]);
    $disabled = User::factory()->create();
    $disabled->forceFill(['disabled_at' => now()])->save();

    $this->post(route('login.store'), ['email' => $passwordless->email, 'password' => 'any-password'])->assertSessionHasErrors('email');
    $this->post(route('login.store'), ['email' => $disabled->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('blocks local password recovery and security management for passwordless accounts', function () {
    Notification::fake();
    $user = User::factory()->passwordless()->create();
    OidcIdentity::factory()->create(['user_id' => $user->id]);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', 'If an eligible account exists, check its inbox for a reset link. If it does not arrive, wait a minute and try again.');
    Notification::assertNothingSent();
    $token = Password::createToken($user);
    $this->post(route('password.update'), [
        'email' => $user->email,
        'token' => $token,
        'password' => 'Sample-passphrase-48!',
        'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertSessionHasErrors('email');
    $this->actingAs($user)->put(route('user-password.update'), ['password' => 'Sample-passphrase-48!'])->assertForbidden();
    $this->get(route('security.edit'))->assertRedirectToRoute('password.confirm');
    $this->post('/user/two-factor-authentication')->assertForbidden();
    $this->get('/user/passkeys/options')->assertForbidden();
    expect($user->fresh()->password)->toBeNull();
});

it('authorizes passkey sign-in only for enabled local-password accounts', function () {
    $local = User::factory()->create();
    $localPasskey = new Passkey;
    $localPasskey->setRelation('user', $local);
    expect(Passkeys::allowsLogin(request(), $localPasskey))->toBeTrue();

    $disabled = User::factory()->create();
    $disabled->forceFill(['disabled_at' => now()]);
    $disabledPasskey = new Passkey;
    $disabledPasskey->setRelation('user', $disabled);
    expect(Passkeys::allowsLogin(request(), $disabledPasskey))->toBeFalse();

    $passwordless = User::factory()->passwordless()->create();
    $oidcPasskey = new Passkey;
    $oidcPasskey->setRelation('user', $passwordless);
    expect(Passkeys::allowsLogin(request(), $oidcPasskey))->toBeFalse();
});

it('keeps an OIDC identity separate from any local account with the same email', function () {
    $user = User::factory()->create(['email' => 'google@example.test']);
    OidcIdentity::factory()->create(['user_id' => $user->id, 'subject' => 'subject-before']);
    [$callback] = beginOidc($this, 'google', ['sub' => 'different-provider-subject', 'email' => $user->email]);

    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Someone Else', 'email' => $user->email])->assertRedirectToRoute('oidc.contact')->assertSessionHas('status', OIDC_CONTACT_STATUS);
    expect(User::count())->toBe(1)->and(OidcIdentity::count())->toBe(1);
});
