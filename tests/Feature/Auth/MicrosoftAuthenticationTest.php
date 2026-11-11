<?php

use App\Models\OidcIdentity;
use App\Models\User;
use App\Notifications\OidcContactVerification;
use App\Services\OidcAuthentication;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

const MS_TEST_TENANT = '11111111-1111-4111-8111-111111111111';
const MS_TEST_SECOND_TENANT = '44444444-4444-4444-8444-444444444444';
const MS_TEST_CLIENT = '22222222-2222-4222-8222-222222222222';
const MS_TEST_OBJECT = '33333333-3333-4333-8333-333333333333';
const MS_CONTACT_STATUS = 'If this address is eligible, check its inbox for a verification link. Open it in this browser session; submit the form again if you need a fresh link.';

beforeEach(function () {
    config(['cache.default' => 'array']);
    $this->withoutMiddleware(ThrottleRequests::class);
    config(['services.microsoft' => [
        'client_id' => MS_TEST_CLIENT,
        'client_secret' => 'fictional-test-secret',
        'redirect_uri' => 'http://localhost:8320/auth/microsoft/callback',
        'tenants' => [
            'example' => ['id' => MS_TEST_TENANT, 'name' => 'Example organization'],
            'second' => ['id' => MS_TEST_SECOND_TENANT, 'name' => 'Second organization'],
        ],
    ]]);
});

function microsoftSignedToken(array $claims, bool $badSignature = false): array
{
    static $key;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $payload = $encode(json_encode(['alg' => 'RS256', 'kid' => 'f30-test-key'])).'.'.$encode(json_encode($claims));
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
    if ($badSignature) {
        $signature[0] = chr(ord($signature[0]) ^ 1);
    }

    return [$payload.'.'.$encode($signature), ['keys' => [[
        'kty' => 'RSA', 'kid' => 'f30-test-key', 'use' => 'sig', 'alg' => 'RS256',
        'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
    ]]]];
}

function microsoftOidcHash(string $value): string
{
    return rtrim(strtr(base64_encode(substr(hash('sha256', $value, true), 0, 16)), '+/', '-_'), '=');
}

/** @param array<string, mixed> $changes */
function beginSharedMicrosoft($test, array $changes = [], bool $badSignature = false, ?callable $metadataChange = null, string $organization = 'example'): array
{
    $tenant = strtolower(config('services.microsoft.tenants.'.$organization.'.id'));
    $authority = 'https://login.microsoftonline.com/'.$tenant;
    $issuer = $authority.'/v2.0';
    $metadata = [
        'issuer' => $issuer,
        'authorization_endpoint' => $authority.'/oauth2/v2.0/authorize',
        'token_endpoint' => $authority.'/oauth2/v2.0/token',
        'jwks_uri' => $authority.'/discovery/v2.0/keys',
        'response_types_supported' => ['code'],
        'subject_types_supported' => ['pairwise'],
        'id_token_signing_alg_values_supported' => ['RS256'],
    ];
    if ($metadataChange !== null) {
        $metadata = $metadataChange($metadata);
    }
    $stub = (object) ['claims' => [], 'requests' => [], 'status' => 200, 'metadata' => $metadata];
    $http = new Client(['handler' => function ($request) use ($stub, $authority, $badSignature) {
        $stub->requests[] = $request;
        $url = (string) $request->getUri();
        if ($url === $authority.'/v2.0/.well-known/openid-configuration') {
            $body = $stub->metadata;
        } else {
            [$token, $keys] = microsoftSignedToken($stub->claims, $badSignature);
            $body = match ($url) {
                $authority.'/oauth2/v2.0/token' => ['id_token' => $token, 'access_token' => 'discarded-test-token', 'token_type' => 'Bearer'],
                $authority.'/discovery/v2.0/keys' => $keys,
                default => throw new RuntimeException('Unexpected synthetic Microsoft request.'),
            };
        }

        return Create::promiseFor(new Response($stub->status, ['Content-Type' => 'application/json'], json_encode($body)));
    }]);
    app()->instance(OidcAuthentication::class, new OidcAuthentication($http));
    $start = $test->post(route('microsoft.start', $organization));
    $start->assertRedirect();
    $transaction = session('oidc.transaction');
    expect($transaction)->toBeArray();
    $stub->claims = [...[
        'iss' => $issuer,
        'aud' => MS_TEST_CLIENT,
        'iat' => time(),
        'nbf' => time() - 1,
        'exp' => time() + 300,
        'nonce' => $transaction['nonce'],
        'tid' => $tenant,
        'oid' => MS_TEST_OBJECT,
        'sub' => 'synthetic-subject-stable-for-tenant',
        'ver' => '2.0',
        'name' => 'Alex Example',
        'email' => 'untrusted-provider-email@example.test',
    ], ...$changes];

    return [route('microsoft.callback', ['state' => $transaction['state'], 'code' => 'synthetic-code']), $stub, $transaction, $start];
}

function microsoftContactUrl(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(OidcContactVerification::class, function ($notification, array $channels, $notifiable) use (&$url, $email): bool {
        $url = $notification->url;

        return $channels === ['mail'] && $notifiable->routeNotificationFor('mail') === $email;
    });

    return $url ?? throw new RuntimeException('No matching shared contact verification was captured.');
}

it('keeps Microsoft disabled until complete configuration and approved tenants exist', function () {
    $oidc = app(OidcAuthentication::class);
    config(['services.microsoft' => null]);
    expect($oidc->providerEnabled('microsoft'))->toBeFalse();
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canMicrosoftLogin', false));
    $this->get(route('microsoft.index'))->assertRedirectToRoute('login');

    config(['services.microsoft' => [
        'client_id' => MS_TEST_CLIENT,
        'client_secret' => 'fictional-test-secret',
        'redirect_uri' => 'http://localhost:8320/auth/microsoft/callback',
        'tenants' => ['invalid' => ['id' => 'not-a-tenant', 'name' => 'Not valid']],
    ]]);
    expect($oidc->providerEnabled('microsoft'))->toBeFalse();
    config(['services.microsoft.tenants' => [
        'consumer' => ['id' => '9188040d-6c67-4c5b-b112-36a304b66dad', 'name' => 'Consumer tenant'],
        'client' => ['id' => MS_TEST_CLIENT, 'name' => 'Client identifier'],
    ]]);
    expect($oidc->providerEnabled('microsoft'))->toBeFalse();
    $this->post(route('microsoft.start', 'arbitrary'))->assertRedirectToRoute('login');
});

it('accepts only the approved callback path and loopback HTTP outside production', function () {
    $oidc = app(OidcAuthentication::class);
    foreach ([null, 8320, 8384, 65535] as $port) {
        $suffix = $port === null ? '' : ':'.$port;
        config(['services.microsoft.redirect_uri' => 'http://localhost'.$suffix.'/auth/microsoft/callback']);
        expect($oidc->providerEnabled('microsoft'))->toBeTrue();
    }
    config(['services.microsoft.redirect_uri' => 'http://login.example.test:8320/auth/microsoft/callback']);
    expect($oidc->providerEnabled('microsoft'))->toBeFalse();
    config(['services.microsoft.redirect_uri' => 'http://localhost:0/auth/microsoft/callback']);
    expect($oidc->providerEnabled('microsoft'))->toBeFalse();
    config(['services.microsoft.redirect_uri' => 'https://localhost:8320/auth/microsoft/callback']);
    expect($oidc->providerEnabled('microsoft'))->toBeFalse();

    $this->app->detectEnvironment(fn () => 'production');
    config(['services.microsoft.redirect_uri' => 'http://localhost:8384/auth/microsoft/callback']);
    expect($oidc->providerEnabled('microsoft'))->toBeFalse();
    config(['services.microsoft.redirect_uri' => 'https://login.example.test/auth/microsoft/callback']);
    expect($oidc->providerEnabled('microsoft'))->toBeTrue();
    $this->app->detectEnvironment(fn () => 'testing');
});

it('shows only server-approved organization names and binds authorization to tenant state nonce and PKCE', function () {
    [$callback, $stub, $transaction, $response] = beginSharedMicrosoft($this);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($callback)->toContain('state='.$transaction['state'])
        ->and($transaction)->toMatchArray(['tenant_slug' => 'example', 'tenant_id' => MS_TEST_TENANT])
        ->and($query)->toMatchArray([
            'scope' => 'openid profile email', 'response_type' => 'code', 'response_mode' => 'query',
            'code_challenge_method' => 'S256', 'state' => $transaction['state'], 'nonce' => $transaction['nonce'],
        ])
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $transaction['code_verifier'], true)), '+/', '-_'), '='));
    expect($query['scope'])->not->toContain('https://graph.microsoft.com');
    foreach ($stub->requests as $request) {
        expect((string) $request->getBody())->not->toContain('discarded-test-token');
    }
    $this->get(route('microsoft.index'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/microsoft')->has('organizations', 2)->where('organizations.0.name', 'Example organization')
        ->missing('client_secret')->missing('tenant_id')->missing('client_id'));
});

it('verifies optional signed state code and access-token hashes for Microsoft callbacks', function (array $changes, bool $accepted) {
    $accessToken = 'discarded-test-token';
    [$callback, $stub, $transaction] = beginSharedMicrosoft($this);
    $expected = [
        's_hash' => microsoftOidcHash($transaction['state']),
        'c_hash' => microsoftOidcHash('synthetic-code'),
        'at_hash' => microsoftOidcHash($accessToken),
    ];
    $stub->claims = [...$stub->claims, ...$expected, ...$changes];
    if ($accepted) {
        $this->get($callback)->assertRedirectToRoute('oidc.contact');
        expect(session('oidc.pending.provider'))->toBe('microsoft');
    } else {
        $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
        expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
    }
})->with([
    'valid optional hashes' => [[], true],
    'bad state hash' => [['s_hash' => 'forged'], false],
    'bad code hash' => [['c_hash' => 'forged'], false],
    'bad access-token hash' => [['at_hash' => 'forged'], false],
]);

it('provisions through the shared contact proof and stores signed tenant object and subject bindings', function () {
    Notification::fake();
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact')->assertSessionMissing('oidc.transaction');
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
    $this->get(route('oidc.contact'))->assertInertia(fn (Assert $page) => $page->component('auth/oidc-contact')
        ->where('suggestedName', 'Alex Example')->missing('issuer')->missing('stable_subject'));
    $this->post(route('oidc.contact.store'), ['name' => 'Alex Example', 'email' => ' Contact@Example.Test '])
        ->assertRedirectToRoute('oidc.contact')->assertSessionHas('status', MS_CONTACT_STATUS);
    $proof = microsoftContactUrl('contact@example.test');
    $this->get($proof)->assertInertia(fn (Assert $page) => $page->component('auth/oidc-contact-confirm')->where('email', 'contact@example.test'));
    $this->post($proof)->assertRedirectToRoute('dashboard');

    $user = User::sole();
    $identity = OidcIdentity::sole();
    expect($user->email)->toBe('contact@example.test')->and($user->password)->toBeNull()->and($user->email_verified_at)->not->toBeNull()
        ->and($identity->provider)->toBe('microsoft')->and($identity->issuer)->toBe('https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0')
        ->and($identity->stable_subject)->toBe(MS_TEST_OBJECT)->and($identity->subject)->toBe('synthetic-subject-stable-for-tenant');
});

it('logs in by the tenant object identifier but rejects a changed signed subject', function () {
    $user = User::factory()->create(['password' => null]);
    OidcIdentity::factory()->create([
        'user_id' => $user->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'synthetic-subject-stable-for-tenant', 'stable_subject' => MS_TEST_OBJECT,
    ]);
    [$callback] = beginSharedMicrosoft($this, ['email' => 'changed-untrusted@example.test']);
    $this->get($callback)->assertRedirectToRoute('dashboard');
    $this->assertAuthenticatedAs($user);

    Auth::logout();
    [$callback] = beginSharedMicrosoft($this, ['sub' => 'different-signed-subject']);
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    $this->assertGuest();
});

it('rejects signed claims outside the exact tenant client and organization policy', function (array $changes) {
    [$callback] = beginSharedMicrosoft($this, $changes);
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
})->with([
    'issuer' => [['iss' => 'https://login.microsoftonline.com/common/v2.0']],
    'tenant' => [['tid' => MS_TEST_SECOND_TENANT]],
    'object id' => [['oid' => 'not-a-uuid']],
    'version' => [['ver' => '1.0']],
    'wrong audience' => [['aud' => 'another-client']],
    'additional audience' => [['aud' => [MS_TEST_CLIENT, 'another-client']]],
    'wrong authorized party' => [['azp' => 'another-client']],
    'null authorized party' => [['azp' => null]],
    'consumer identity provider' => [['idp' => 'https://login.microsoftonline.com/'.'9188040d-6c67-4c5b-b112-36a304b66dad']],
    'live identity provider' => [['idp' => 'live.com']],
    'missing subject' => [['sub' => '']],
]);

it('rejects forged signatures and metadata that leaves the tenant-specific endpoint set', function () {
    [$callback] = beginSharedMicrosoft($this, [], true);
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('rejects Microsoft discovery metadata that leaves the tenant-specific endpoint set', function () {
    $authority = 'https://login.microsoftonline.com/'.MS_TEST_TENANT;
    $metadata = [
        'issuer' => $authority.'/v2.0',
        'authorization_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token_endpoint' => $authority.'/oauth2/v2.0/token',
        'jwks_uri' => $authority.'/discovery/v2.0/keys',
        'response_types_supported' => ['code'],
        'subject_types_supported' => ['pairwise'],
        'id_token_signing_alg_values_supported' => ['RS256'],
    ];
    app()->instance(OidcAuthentication::class, new OidcAuthentication(new Client([
        'handler' => fn () => new Response(200, ['Content-Type' => 'application/json'], json_encode($metadata)),
    ])));

    $this->post(route('microsoft.start', 'example'))->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    expect(session('oidc.transaction'))->toBeNull();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('rejects state mismatch expiry replay and tenant remapping before identity use', function () {
    [$callback, , $transaction] = beginSharedMicrosoft($this);
    $absentState = preg_replace('/([?&])state=[^&]*(&?)/', '$1', $callback);
    $absentState = rtrim(str_replace('?&', '?', (string) $absentState), '?&');
    $this->get($absentState)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');

    [$callback, , $transaction] = beginSharedMicrosoft($this);
    $badState = str_replace($transaction['state'], str_repeat('x', strlen($transaction['state'])), $callback);
    $this->get($badState)->assertRedirectToRoute('login');
    $this->withSession(['oidc.transaction' => [...$transaction, 'provider' => 'microsoft', 'expires_at' => now()->subSecond()->timestamp]])
        ->get($callback)->assertRedirectToRoute('login');

    [$callback] = beginSharedMicrosoft($this);
    $snapshot = session()->all();
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->withSession($snapshot)->get($callback)->assertRedirectToRoute('login');

    [$callback] = beginSharedMicrosoft($this);
    config(['services.microsoft.tenants.example.id' => MS_TEST_SECOND_TENANT]);
    $this->get($callback)->assertRedirectToRoute('login');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('returns a neutral error for consent denial and does not reuse expired or replaced contact proofs', function () {
    Notification::fake();
    [$callback, , $transaction] = beginSharedMicrosoft($this);
    $this->get(route('microsoft.callback', [
        'state' => $transaction['state'], 'error' => 'access_denied', 'error_description' => 'private provider detail',
    ]))->assertRedirectToRoute('login')->assertSessionHasErrors(['oidc' => 'Sign-in was cancelled or declined. Please try again.']);

    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'first@example.test'])->assertRedirectToRoute('oidc.contact');
    $firstProof = microsoftContactUrl('first@example.test');
    $pending = session('oidc.pending');
    $this->withSession(['oidc.pending' => [...$pending, 'expires_at' => now()->subSecond()->timestamp]])
        ->post($firstProof)->assertRedirectToRoute('login');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);

    $this->withSession(['oidc.pending' => $pending]);
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'second@example.test'])->assertRedirectToRoute('oidc.contact');
    $secondProof = microsoftContactUrl('second@example.test');
    $this->post($firstProof)->assertRedirectToRoute('login');
    $this->get($secondProof)->assertInertia(fn (Assert $page) => $page->component('auth/oidc-contact-confirm'));
    $snapshot = session()->all();
    $this->post($secondProof)->assertRedirectToRoute('dashboard');
    Auth::logout();
    $this->withSession($snapshot)->post($secondProof)->assertRedirectToRoute('login');
    expect(User::count())->toBe(1)->and(OidcIdentity::count())->toBe(1);
});

it('validates required contact fields and expires the pending Microsoft transaction', function () {
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $pending = session('oidc.pending');
    $this->post(route('oidc.contact.store'), [])->assertSessionHasErrors(['name', 'email']);
    $this->withSession(['oidc.pending' => [...$pending, 'expires_at' => now()->subSecond()->timestamp]])
        ->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])
        ->assertRedirectToRoute('login');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('keeps authenticated accounts separate from Microsoft callback and contact routes', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('microsoft.callback', ['state' => 'synthetic-state', 'code' => 'synthetic-code']))
        ->assertRedirectToRoute('dashboard');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])
        ->assertRedirectToRoute('dashboard');
    $this->assertAuthenticatedAs($user);
    expect(OidcIdentity::count())->toBe(0);
});

it('fails closed for malformed Microsoft configuration without returning its details', function (array $changes) {
    config($changes);
    $this->get(route('login'))->assertOk();
    $this->post(route('microsoft.start', 'example'))->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
})->with([
    'missing client id' => [['services.microsoft.client_id' => null]],
    'missing client secret' => [['services.microsoft.client_secret' => null]],
    'invalid tenants shape' => [['services.microsoft.tenants' => true]],
    'client id used as tenant' => [['services.microsoft.tenants.example.id' => MS_TEST_CLIENT]],
    'consumer tenant' => [['services.microsoft.tenants.example.id' => '9188040d-6c67-4c5b-b112-36a304b66dad']],
]);

it('never email-links or reserves a conflicting contact address before signed proof', function () {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'Untrusted-Provider-Email@example.test']);
    DB::table('users')->where('id', $existing->id)->update(['email' => 'Untrusted-Provider-Email@example.test']);
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'untrusted-provider-email@example.test'])
        ->assertRedirectToRoute('oidc.contact')->assertSessionHas('status', MS_CONTACT_STATUS);
    Notification::assertNothingSent();
    expect(User::count())->toBe(1)->and(OidcIdentity::count())->toBe(0)
        ->and($existing->fresh()->email)->toBe('Untrusted-Provider-Email@example.test');

    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'first@example.test'])->assertRedirectToRoute('oidc.contact');
    $proof = microsoftContactUrl('first@example.test');
    $this->flushSession();
    $this->post($proof)->assertRedirectToRoute('login');
    expect(User::count())->toBe(1)->and(OidcIdentity::count())->toBe(0);
});

it('keeps the original pending proof usable after a different contact address collides', function () {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'already-owned@example.test']);
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'available@example.test'])
        ->assertRedirectToRoute('oidc.contact');
    $originalProof = microsoftContactUrl('available@example.test');

    $this->post(route('oidc.contact.store'), ['name' => 'Alex Changed', 'email' => $existing->email])
        ->assertRedirectToRoute('oidc.contact')->assertSessionHas('status', MS_CONTACT_STATUS);
    Notification::assertSentOnDemandTimes(OidcContactVerification::class, 1);
    $this->get($originalProof)->assertInertia(fn (Assert $page) => $page->component('auth/oidc-contact-confirm')
        ->where('email', 'available@example.test'));
    $this->post($originalProof)->assertRedirectToRoute('dashboard');

    expect(User::count())->toBe(2)
        ->and(OidcIdentity::count())->toBe(1)
        ->and($existing->fresh()->email)->toBe('already-owned@example.test')
        ->and(User::where('email', 'available@example.test')->exists())->toBeTrue();
});

it('lets local registration claim an unreserved pending Microsoft contact address', function () {
    Notification::fake();
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Local Owner', 'email' => 'local-owner@example.test'])
        ->assertRedirectToRoute('oidc.contact');
    $providerProof = microsoftContactUrl('local-owner@example.test');

    $this->post(route('register.store'), [
        'name' => 'Local Owner',
        'email' => 'local-owner@example.test',
        'password' => 'Sample-passphrase-48!',
        'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertRedirectToRoute('login');
    $localOwner = User::sole();
    $this->assertGuest();
    expect($localOwner->password)->not->toBeNull()->and(OidcIdentity::count())->toBe(0);

    $this->post($providerProof)->assertRedirectToRoute('oidc.contact');
    expect(User::sole()->is($localOwner))->toBeTrue()
        ->and(User::sole()->password)->not->toBeNull()
        ->and(OidcIdentity::count())->toBe(0);
});

it('rechecks the tenant allowlist for pending contact proof and established accounts', function () {
    Notification::fake();
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'tenant-proof@example.test'])->assertRedirectToRoute('oidc.contact');
    $proof = microsoftContactUrl('tenant-proof@example.test');
    config(['services.microsoft.tenants' => []]);
    $this->get(route('oidc.contact'))->assertRedirectToRoute('login');
    $this->post($proof)->assertRedirectToRoute('login');
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);

    config(['services.microsoft.tenants' => ['example' => ['id' => MS_TEST_TENANT, 'name' => 'Example organization']]]);
    $user = User::factory()->create(['password' => null]);
    $identity = OidcIdentity::factory()->create([
        'user_id' => $user->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'stable_subject' => MS_TEST_OBJECT, 'subject' => 'synthetic-subject-stable-for-tenant',
    ]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    config(['services.microsoft.tenants' => []]);
    $this->get(route('dashboard'))->assertForbidden();
    $this->assertGuest();
    $this->assertModelExists($identity);
});

it('fails closed when Microsoft discovery or the shared transaction cache is unavailable', function (string $failure) {
    [$callback, $stub] = beginSharedMicrosoft($this);

    if ($failure === 'provider') {
        $stub->status = 503;
    } else {
        $store = app('cache')->store();
        Cache::shouldReceive('driver')->andReturn($store);
        Cache::shouldReceive('store')->andReturn($store);
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('private cache detail'));
        Cache::shouldReceive('store')->andReturn($store);
    }

    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
})->with(['provider', 'cache']);

it('fails closed when Microsoft discovery is unavailable before authorization starts', function () {
    app()->instance(OidcAuthentication::class, new OidcAuthentication(new Client([
        'handler' => fn () => new Response(503, ['Content-Type' => 'application/json'], '{}'),
    ])));

    $this->post(route('microsoft.start', 'example'))->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('keeps Microsoft contact onboarding unpersisted when the verification transport fails', function () {
    config(['queue.default' => 'sync', 'mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Synthetic mail transport unavailable');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
    Exceptions::fake([TransportException::class]);
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');

    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0)
        ->and(session('oidc.pending.contact.email'))->toBe('contact@example.test');
    Exceptions::assertReported(TransportException::class);
});

it('requires CSRF for Microsoft start and shared contact/proof POSTs', function () {
    app()->instance('env', 'local');
    $this->post(route('microsoft.start', 'example'))->assertStatus(419);
    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])->assertStatus(419);
    $proof = URL::temporarySignedRoute('oidc.contact.verify', now()->addMinute(), ['proof' => 'synthetic-proof']);
    $this->post($proof)->assertStatus(419);
    expect(User::count())->toBe(0)->and(OidcIdentity::count())->toBe(0);
});

it('serializes duplicate Microsoft callbacks or contact proofs for the same browser session', function (string $phase) {
    if (getenv('MOMENTO_OIDC_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the isolated PostgreSQL same-session concurrency fixture.');
    }

    DB::commit();
    $directory = sys_get_temp_dir().'/momento-oidc-ms-session-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $ids = [];
    $cachePrefix = 'momento-oidc-ms-lock-'.bin2hex(random_bytes(8));
    $email = null;

    try {
        config(['cache.default' => 'database', 'cache.prefix' => $cachePrefix, 'session.block_store' => 'database']);
        app('cache')->forgetDriver();
        app()->forgetInstance(RateLimiter::class);
        app()->make(RateLimiter::class);
        [$callback, $stub] = beginSharedMicrosoft($this);
        $target = $callback;
        $method = 'GET';
        if ($phase === 'proof') {
            Notification::fake();
            $this->get($callback)->assertRedirectToRoute('oidc.contact');
            $email = 'same-session-'.bin2hex(random_bytes(8)).'@example.test';
            $this->post(route('oidc.contact.store'), ['name' => 'Alex Example', 'email' => $email])->assertRedirectToRoute('oidc.contact');
            $target = microsoftContactUrl($email);
            $method = 'POST';
        }
        [$token, $jwks] = microsoftSignedToken($stub->claims);
        $sessionId = session()->getId();
        $ids[] = $sessionId;
        $cookieName = config('session.cookie');
        $encrypter = app(Encrypter::class);
        $cookie = $encrypter->encrypt(CookieValuePrefix::create($cookieName, $encrypter->getKey()).$sessionId, false);
        DB::table('sessions')->updateOrInsert(['id' => $sessionId], [
            'user_id' => null,
            'payload' => base64_encode(json_encode(session()->all(), JSON_THROW_ON_ERROR)),
            'last_activity' => now()->timestamp,
        ]);
        $settings = config('services.microsoft');
        $tasks = [];
        $tenantId = MS_TEST_TENANT;

        foreach ([1, 2] as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $target, $method, $token, $jwks, $cookieName, $cookie, $settings, $cachePrefix, $tenantId): array {
                config([
                    'services.microsoft' => $settings,
                    'cache.default' => 'database',
                    'cache.prefix' => $cachePrefix,
                    'session.block_store' => 'database',
                ]);
                app('cache')->forgetDriver();
                app()->forgetInstance(RateLimiter::class);
                app()->make(RateLimiter::class);
                $authority = 'https://login.microsoftonline.com/'.$tenantId;
                $metadata = [
                    'issuer' => $authority.'/v2.0',
                    'authorization_endpoint' => $authority.'/oauth2/v2.0/authorize',
                    'token_endpoint' => $authority.'/oauth2/v2.0/token',
                    'jwks_uri' => $authority.'/discovery/v2.0/keys',
                    'response_types_supported' => ['code'],
                    'subject_types_supported' => ['pairwise'],
                    'id_token_signing_alg_values_supported' => ['RS256'],
                ];
                $http = new Client(['handler' => static function ($request) use ($authority, $metadata, $token, $jwks) {
                    $body = match ((string) $request->getUri()) {
                        $authority.'/v2.0/.well-known/openid-configuration' => $metadata,
                        $authority.'/oauth2/v2.0/token' => ['id_token' => $token, 'access_token' => 'discarded-test-token', 'token_type' => 'Bearer'],
                        $authority.'/discovery/v2.0/keys' => $jwks,
                        default => throw new RuntimeException('Unexpected synthetic callback request.'),
                    };

                    return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
                }]);
                app()->instance(OidcAuthentication::class, new OidcAuthentication($http));
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*') ?: []) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Same-session callback workers did not synchronize.');
                    }
                    usleep(10000);
                }

                $request = Request::create($target, $method, [], [$cookieName => $cookie]);
                $kernel = app(Kernel::class);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);

                return [$response->getStatusCode(), parse_url($response->headers->get('Location', ''), PHP_URL_PATH), $request->session()->getId()];
            }, null, null);
        }

        $results = Concurrency::driver('process')->run($tasks, timeout: 20);
        $ids = [...$ids, ...array_column($results, 2)];
        $locations = array_column($results, 1);
        sort($locations);
        $expectedLocations = $phase === 'callback'
            ? ['/auth/oidc/contact', '/login']
            : ['/dashboard', '/login'];
        $expectedAccounts = $phase === 'callback' ? 0 : 1;
        expect(array_column($results, 0))->toBe([302, 302])
            ->and($locations)->toBe($expectedLocations)
            ->and(User::count())->toBe($expectedAccounts)
            ->and(OidcIdentity::count())->toBe($expectedAccounts);
    } finally {
        DB::table('sessions')->whereIn('id', array_unique($ids))->delete();
        if ($email !== null) {
            User::where('email', $email)->delete();
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
})->with(['callback', 'proof']);

it('serializes a slow contact page load with a contact update for that browser session', function () {
    if (getenv('MOMENTO_OIDC_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the isolated PostgreSQL same-session concurrency fixture.');
    }

    DB::commit();
    Notification::fake();
    $directory = sys_get_temp_dir().'/momento-oidc-ms-contact-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $ids = [];
    $oldEmail = 'before-'.bin2hex(random_bytes(8)).'@example.test';
    $newEmail = 'after-'.bin2hex(random_bytes(8)).'@example.test';
    $cachePrefix = 'momento-oidc-ms-contact-lock-'.bin2hex(random_bytes(8));

    try {
        config(['cache.default' => 'database', 'cache.prefix' => $cachePrefix, 'session.block_store' => 'database']);
        app('cache')->forgetDriver();
        app()->forgetInstance(RateLimiter::class);
        app()->make(RateLimiter::class);
        [$callback] = beginSharedMicrosoft($this);
        $this->get($callback)->assertRedirectToRoute('oidc.contact');
        $this->post(route('oidc.contact.store'), ['name' => 'Concurrent Example', 'email' => $oldEmail])->assertRedirectToRoute('oidc.contact');
        $oldProof = session('oidc.pending.proof');
        $oldProofUrl = microsoftContactUrl($oldEmail);

        $sessionId = session()->getId();
        $ids[] = $sessionId;
        $cookieName = config('session.cookie');
        $encrypter = app(Encrypter::class);
        $cookie = $encrypter->encrypt(CookieValuePrefix::create($cookieName, $encrypter->getKey()).$sessionId, false);
        DB::table('sessions')->updateOrInsert(['id' => $sessionId], [
            'user_id' => null,
            'payload' => base64_encode(json_encode(session()->all(), JSON_THROW_ON_ERROR)),
            'last_activity' => now()->timestamp,
        ]);
        $settings = config('services.microsoft');

        $get = Closure::bind(static function () use ($directory, $cookieName, $cookie, $settings, $cachePrefix): array {
            config([
                'services.microsoft' => $settings,
                'cache.default' => 'database',
                'cache.prefix' => $cachePrefix,
                'session.block_store' => 'database',
            ]);
            app('cache')->forgetDriver();
            app()->forgetInstance(RateLimiter::class);
            app()->make(RateLimiter::class);
            app()->instance(OidcAuthentication::class, new class($directory) extends OidcAuthentication
            {
                public function __construct(private string $directory)
                {
                    parent::__construct(new Client);
                }

                public function providerAllowsIdentity(string $provider, string $issuer, ?string $institutionDomain, ?string $stableSubject = null): bool
                {
                    touch($this->directory.'/get-loaded');
                    $deadline = microtime(true) + 10;
                    while (! file_exists($this->directory.'/post-start')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Contact GET did not observe the concurrent update.');
                        }
                        usleep(10000);
                    }
                    usleep(1200000);

                    return parent::providerAllowsIdentity($provider, $issuer, $institutionDomain, $stableSubject);
                }
            });

            $request = Request::create('/auth/oidc/contact', 'GET', [], [$cookieName => $cookie]);
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return [$response->getStatusCode(), $request->session()->getId()];
        }, null, null);

        $post = Closure::bind(static function () use ($directory, $cookieName, $cookie, $settings, $cachePrefix, $newEmail): array {
            config([
                'services.microsoft' => $settings,
                'cache.default' => 'database',
                'cache.prefix' => $cachePrefix,
                'session.block_store' => 'database',
            ]);
            app('cache')->forgetDriver();
            app()->forgetInstance(RateLimiter::class);
            app()->make(RateLimiter::class);
            Notification::fake();
            $deadline = microtime(true) + 10;
            while (! file_exists($directory.'/get-loaded')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Contact update did not observe the page load.');
                }
                usleep(10000);
            }
            touch($directory.'/post-start');

            $request = Request::create('/auth/oidc/contact', 'POST', [
                'name' => 'Concurrent Example', 'email' => $newEmail,
            ], [$cookieName => $cookie]);
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return [$response->getStatusCode(), parse_url($response->headers->get('Location', ''), PHP_URL_PATH), $request->session()->getId()];
        }, null, null);

        $results = Concurrency::driver('process')->run([$get, $post], timeout: 20);
        $ids = [...$ids, $results[0][1], $results[1][2]];
        expect($results[0][0])->toBe(200)->and($results[1][0])->toBe(302)
            ->and($results[1][1])->toBe('/auth/oidc/contact');

        $payload = DB::table('sessions')->where('id', $sessionId)->value('payload');
        $saved = json_decode(base64_decode($payload, true), true, 512, JSON_THROW_ON_ERROR);
        $pending = $saved['oidc']['pending'];
        expect($pending['contact']['email'])->toBe($newEmail)
            ->and($pending['proof'])->not->toBe($oldProof);
        $this->withUnencryptedCookie($cookieName, $cookie)->post($oldProofUrl)->assertRedirectToRoute('login');
        $currentProofUrl = URL::temporarySignedRoute(
            'oidc.contact.verify', now()->addMinute(), ['proof' => $pending['proof']],
        );
        $this->withUnencryptedCookie($cookieName, $cookie)->get($currentProofUrl)
            ->assertInertia(fn (Assert $page) => $page->component('auth/oidc-contact-confirm')->where('email', $newEmail));
        $this->withUnencryptedCookie($cookieName, $cookie)->post($currentProofUrl)->assertRedirectToRoute('dashboard');
        expect(User::where('email', $newEmail)->count())->toBe(1)
            ->and(User::where('email', $oldEmail)->count())->toBe(0)
            ->and(OidcIdentity::count())->toBe(1);
    } finally {
        DB::table('sessions')->whereIn('id', array_unique($ids))->delete();
        User::whereIn('email', [$oldEmail, $newEmail])->delete();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});

it('revokes Microsoft sessions as JSON or Inertia after account identity or tenant access is removed', function (string $condition, string $responseType) {
    $user = User::factory()->passwordless()->create();
    $identity = OidcIdentity::factory()->create([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'synthetic-subject-stable-for-tenant',
        'stable_subject' => MS_TEST_OBJECT,
    ]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    if ($condition === 'tenant') {
        config(['services.microsoft.tenants' => []]);
    } elseif ($condition === 'account') {
        $user->forceFill(['disabled_at' => now()])->save();
    } else {
        $identity->forceFill(['disabled_at' => now()])->save();
    }

    $request = $responseType === 'json'
        ? $this->withHeader('Accept', 'application/json')
        : $this->withHeaders([
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    $response = $request->get(route('dashboard'))->assertForbidden();

    if ($responseType === 'json') {
        $response->assertJsonMissingPath('component');
    } else {
        $response->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'access-denied')
            ->assertJsonPath('props.auth.user', null)
            ->assertJsonPath('clearHistory', true)
            ->assertJsonPath('encryptHistory', true);
    }

    $this->assertGuest();
})->with([
    'removed tenant JSON' => ['tenant', 'json'],
    'disabled identity JSON' => ['identity', 'json'],
    'disabled account JSON' => ['account', 'json'],
    'disabled identity Inertia' => ['identity', 'inertia'],
    'disabled account Inertia' => ['account', 'inertia'],
    'removed tenant Inertia' => ['tenant', 'inertia'],
]);

it('rotates and persists an anonymous session after a Microsoft JSON access revocation', function () {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    $user = User::factory()->passwordless()->create();
    $identity = OidcIdentity::factory()->create([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'synthetic-subject-stable-for-tenant',
        'stable_subject' => MS_TEST_OBJECT,
    ]);
    [$callback, , , $start] = beginSharedMicrosoft($this);
    $cookieName = config('session.cookie');
    $guestCookie = $start->getCookie($cookieName)->getValue();

    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers();

    $login = $this->withCookie($cookieName, $guestCookie)->get($callback)->assertRedirectToRoute('dashboard');
    $authenticatedCookie = $login->getCookie($cookieName)->getValue();
    expect($authenticatedCookie)->not->toBe($guestCookie);
    $this->assertDatabaseHas('sessions', ['id' => $authenticatedCookie, 'user_id' => $user->id]);
    $identity->forceFill(['disabled_at' => now()])->save();

    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers();

    $denial = $this->withCookie($cookieName, $authenticatedCookie)
        ->withHeader('Accept', 'application/json')
        ->get(route('dashboard'))->assertForbidden()->assertJsonMissingPath('component');
    $revokedCookie = $denial->getCookie($cookieName)->getValue();
    expect($revokedCookie)->not->toBe($authenticatedCookie);
    $this->assertDatabaseMissing('sessions', ['id' => $authenticatedCookie]);
    $this->assertDatabaseHas('sessions', ['id' => $revokedCookie, 'user_id' => null]);

    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers();
    $this->withHeader('Accept', 'text/html')->withCookie($cookieName, $revokedCookie)
        ->get(route('dashboard'))->assertRedirectToRoute('login');
    $this->assertGuest();
});

it('denies disabled Microsoft identities and users at provider login', function (string $disabled) {
    $user = User::factory()->create(['password' => null, 'disabled_at' => $disabled === 'user' ? now() : null]);
    OidcIdentity::factory()->create([
        'user_id' => $user->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'synthetic-subject-stable-for-tenant', 'stable_subject' => MS_TEST_OBJECT,
        'disabled_at' => $disabled === 'identity' ? now() : null,
    ]);
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('oidc');
    $this->assertGuest();
})->with(['user', 'identity']);

it('keeps provider-only Microsoft accounts outside local recovery and security changes', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => null]);
    OidcIdentity::factory()->create([
        'user_id' => $user->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'synthetic-subject-stable-for-tenant', 'stable_subject' => MS_TEST_OBJECT,
    ]);

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    Notification::assertNothingSent();
    $token = Password::createToken($user);
    $this->post(route('password.update'), [
        'email' => $user->email,
        'token' => $token,
        'password' => 'Sample-passphrase-48!',
        'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertSessionHasErrors('email');
    $this->actingAs($user)->put(route('user-password.update'), ['password' => 'Sample-passphrase-48!'])->assertForbidden();
    $this->get(route('security.edit'))->assertForbidden();
    $this->post('/user/two-factor-authentication')->assertForbidden();
    $this->get('/user/passkeys/options')->assertForbidden();
    expect($user->fresh()->password)->toBeNull();

    $local = User::factory()->create();
    $this->actingAs($local)->get(route('security.edit'))->assertRedirectToRoute('password.confirm');
});

it('keeps Microsoft stable subjects unique within their issuer without linking across providers', function () {
    $first = User::factory()->create(['password' => null]);
    $second = User::factory()->create(['password' => null]);
    OidcIdentity::factory()->create([
        'user_id' => $first->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'microsoft-subject-one', 'stable_subject' => MS_TEST_OBJECT,
    ]);

    expect(fn () => DB::transaction(fn () => OidcIdentity::factory()->create([
        'user_id' => $second->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'microsoft-subject-two', 'stable_subject' => MS_TEST_OBJECT,
    ])))->toThrow(UniqueConstraintViolationException::class);

    OidcIdentity::factory()->create([
        'user_id' => $second->id, 'provider' => 'google', 'issuer' => 'https://accounts.google.com',
        'subject' => 'google-subject', 'stable_subject' => MS_TEST_OBJECT,
    ]);
    $third = User::factory()->create(['password' => null]);
    OidcIdentity::factory()->create([
        'user_id' => $third->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_SECOND_TENANT.'/v2.0',
        'subject' => 'other-tenant-subject', 'stable_subject' => MS_TEST_OBJECT,
    ]);
    expect(OidcIdentity::count())->toBe(3);
});

it('rechecks stable identity ownership before sending shared contact proof', function () {
    Notification::fake();
    [$callback] = beginSharedMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('oidc.contact');
    $owner = User::factory()->create(['password' => null]);
    OidcIdentity::factory()->create([
        'user_id' => $owner->id, 'provider' => 'microsoft',
        'issuer' => 'https://login.microsoftonline.com/'.MS_TEST_TENANT.'/v2.0',
        'subject' => 'synthetic-subject-stable-for-tenant', 'stable_subject' => MS_TEST_OBJECT,
    ]);

    $this->post(route('oidc.contact.store'), ['name' => 'Alex', 'email' => 'separate@example.test'])
        ->assertRedirectToRoute('oidc.contact')->assertSessionHas('status', MS_CONTACT_STATUS);
    Notification::assertNothingSent();
    expect(User::count())->toBe(1)->and(OidcIdentity::count())->toBe(1);
});
