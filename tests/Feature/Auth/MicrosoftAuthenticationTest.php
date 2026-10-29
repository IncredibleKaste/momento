<?php

use App\Models\MicrosoftIdentity;
use App\Models\User;
use App\Notifications\VerifyEmail;
use App\Services\MicrosoftOidc;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

const MICROSOFT_TEST_TENANT = '11111111-1111-4111-8111-111111111111';
const MICROSOFT_TEST_CLIENT = '22222222-2222-4222-8222-222222222222';
const MICROSOFT_TEST_OBJECT = '33333333-3333-4333-8333-333333333333';

beforeEach(function () {
    $addressHash = hash('sha256', $this->name());
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:'.substr($addressHash, 0, 4).':'.substr($addressHash, 4, 4).'::1']);
    config(['services.microsoft' => [
        'client_id' => MICROSOFT_TEST_CLIENT, 'client_secret' => 'fictional-test-secret',
        'redirect_uri' => 'http://localhost/auth/microsoft/callback',
        'tenants' => ['example' => ['id' => MICROSOFT_TEST_TENANT, 'name' => 'Example organization']],
    ]]);
});

function microsoftToken(array $claims, bool $badSignature = false): array
{
    static $key;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $payload = $encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key'])).'.'.$encode(json_encode($claims));
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
    if ($badSignature) {
        $signature[0] = chr(ord($signature[0]) ^ 1);
    }

    return [$payload.'.'.$encode($signature), ['keys' => [[
        'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
        'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
    ]]]];
}

function beginMicrosoft($test, array $changes = [], bool $badSignature = false): array
{
    $stub = (object) ['claims' => [], 'requests' => [], 'status' => 200];
    $authority = 'https://login.microsoftonline.com/'.MICROSOFT_TEST_TENANT;
    app()->instance(MicrosoftOidc::class, new MicrosoftOidc(new Client(['handler' => function ($request) use ($stub, $authority, $badSignature) {
        $stub->requests[] = $request;
        $url = (string) $request->getUri();
        if ($url === $authority.'/v2.0/.well-known/openid-configuration') {
            $body = ['issuer' => $authority.'/v2.0', 'authorization_endpoint' => $authority.'/oauth2/v2.0/authorize',
                'token_endpoint' => $authority.'/oauth2/v2.0/token', 'jwks_uri' => $authority.'/discovery/v2.0/keys',
                'response_types_supported' => ['code'], 'subject_types_supported' => ['pairwise'],
                'id_token_signing_alg_values_supported' => ['RS256']];
        } else {
            [$token, $keys] = microsoftToken($stub->claims, $badSignature);
            $body = match ($url) {
                $authority.'/oauth2/v2.0/token' => ['id_token' => $token, 'access_token' => 'discarded-access-token', 'token_type' => 'Bearer'],
                $authority.'/discovery/v2.0/keys' => $keys,
                default => throw new RuntimeException('Unexpected outbound request.'),
            };
        }

        return Create::promiseFor(new Response($stub->status, ['Content-Type' => 'application/json'], json_encode($body)));
    }])));
    $response = $test->post(route('microsoft.start', 'example'))->assertRedirect();
    $transaction = session('microsoft.transaction');
    expect($transaction)->toBeArray();
    $stub->claims = [...[
        'iss' => $authority.'/v2.0', 'aud' => MICROSOFT_TEST_CLIENT,
        'iat' => time(), 'nbf' => time() - 1, 'exp' => time() + 300,
        'nonce' => $transaction['nonce'], 'tid' => MICROSOFT_TEST_TENANT,
        'oid' => MICROSOFT_TEST_OBJECT, 'sub' => 'test-subject', 'ver' => '2.0',
        'name' => 'Alex Example', 'email' => 'untrusted@example.test',
    ], ...$changes];

    return [route('microsoft.callback', ['state' => $transaction['state'], 'code' => 'test-authorization-code']), $stub, $transaction, $response];
}

it('binds authorization to an approved organization with nonce and S256 and no Graph scopes', function () {
    [, , $transaction, $response] = beginMicrosoft($this);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['scope' => 'openid profile email', 'response_type' => 'code', 'code_challenge_method' => 'S256', 'state' => $transaction['state'], 'nonce' => $transaction['nonce']]);
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $transaction['code_verifier'], true)), '+/', '-_'), '='));
    $this->get(route('microsoft.index'))->assertInertia(fn (Assert $page) => $page->component('auth/microsoft')->has('organizations', 1)->missing('client_secret')->missing('tenant_id'));
});

it('provisions a passwordless account only after contact collection and requires email verification', function () {
    Notification::fake();
    [$callback, $stub, $transaction] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact')->assertSessionMissing('microsoft.transaction');
    $this->assertGuest();
    expect(User::count())->toBe(0);
    $this->get(route('microsoft.contact'))->assertInertia(fn (Assert $page) => $page->component('auth/microsoft-contact')->where('suggestedName', 'Alex Example')->missing('tenant_id')->missing('subject')->missing('id_token'));
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex Example', 'email' => ' Contact@Example.Test '])->assertRedirectToRoute('verification.notice');
    $user = User::sole();
    expect($user->email)->toBe('contact@example.test');
    expect($user->password)->toBeNull();
    expect($user->email_verified_at)->toBeNull();
    expect(MicrosoftIdentity::sole()->user_id)->toBe($user->id);
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get(route('dashboard'))->assertRedirectToRoute('verification.notice');
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($url)->assertRedirect();
    $this->get(route('dashboard'))->assertOk();
    $tokenRequest = collect($stub->requests)->first(fn ($request) => $request->getMethod() === 'POST');
    parse_str((string) $tokenRequest->getBody(), $body);
    expect($body)->toMatchArray(['code' => 'test-authorization-code', 'code_verifier' => $transaction['code_verifier'], 'redirect_uri' => 'http://localhost/auth/microsoft/callback']);
    expect(session()->all())->not->toHaveKey('access_token')->not->toHaveKey('id_token');
});

it('resolves by stable identity despite a changed provider email', function () {
    $user = User::factory()->create(['password' => null]);
    MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => MICROSOFT_TEST_TENANT, 'object_id' => MICROSOFT_TEST_OBJECT, 'subject' => 'test-subject']);
    [$callback] = beginMicrosoft($this, ['email' => 'changed@example.test']);
    $this->get($callback)->assertRedirectToRoute('dashboard');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
    expect($user->fresh()->email)->toBe($user->email);
});

it('denies invalid or unapproved signed claims without provisioning', function (array $changes) {
    [$callback] = beginMicrosoft($this, $changes);
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('microsoft')->assertSessionMissing('microsoft.pending');
    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'wrong audience' => [['aud' => 'different-application']],
    'wrong issuer' => [['iss' => 'https://issuer.example.test']],
    'unapproved tenant' => [['tid' => '44444444-4444-4444-8444-444444444444']],
    'personal tenant' => [['tid' => MicrosoftOidc::CONSUMER_TENANT]],
    'personal guest' => [['idp' => 'live.com']],
    'consumer identity provider' => [['idp' => 'https://sts.windows.net/'.MicrosoftOidc::CONSUMER_TENANT.'/']],
    'wrong nonce' => [['nonce' => 'another-transaction']],
    'expired' => [['exp' => 1]],
    'future token' => [['nbf' => time() + 600]],
    'missing object identity' => [['oid' => null]],
    'missing subject' => [['sub' => null]],
    'wrong protocol version' => [['ver' => '1.0']],
]);

it('rejects a forged signature', function () {
    [$callback] = beginMicrosoft($this, [], true);
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('microsoft');
    $this->assertGuest();
});

it('rejects stale or replayed callbacks even when the old session snapshot is restored', function () {
    [$callback, $stub, $transaction] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $requests = count($stub->requests);
    $this->withSession(['microsoft.transaction' => $transaction])->get($callback)->assertRedirectToRoute('login')->assertSessionMissing('microsoft.pending');
    expect(count($stub->requests))->toBe($requests);
    $this->withSession(['microsoft.transaction' => [...$transaction, 'expires_at' => now()->subSecond()->timestamp]])->get($callback)->assertRedirectToRoute('login');
});

it('rejects absent or mismatched state and consent denial without exposing provider text', function () {
    [$callback, $stub] = beginMicrosoft($this);
    $this->get(route('microsoft.callback', ['state' => 'wrong', 'code' => 'test']))->assertRedirectToRoute('login');
    expect($stub->requests)->toHaveCount(1);
    [$callback] = beginMicrosoft($this);
    $this->get($callback.'&error=access_denied&error_description=private-provider-error')->assertRedirectToRoute('login')->assertSessionHasErrors(['microsoft' => 'Microsoft sign-in was cancelled or declined. You can try again.']);
});

it('does not link an existing email or reuse an already owned identity', function () {
    User::factory()->create(['email' => 'existing@example.test']);
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $pending = session('microsoft.pending');
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'existing@example.test'])->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(MicrosoftIdentity::count())->toBe(0);
    MicrosoftIdentity::factory()->create(['tenant_id' => MICROSOFT_TEST_TENANT, 'object_id' => MICROSOFT_TEST_OBJECT, 'subject' => 'test-subject']);
    $this->withSession(['microsoft.pending' => $pending])->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'separate@example.test'])->assertRedirectToRoute('login');
    expect(User::count())->toBe(2);
    $this->assertGuest();
});

it('denies disabled or reassigned identities', function (string $condition) {
    $user = User::factory()->create(['password' => null, 'disabled_at' => $condition === 'account' ? now() : null]);
    MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => MICROSOFT_TEST_TENANT, 'object_id' => MICROSOFT_TEST_OBJECT,
        'subject' => $condition === 'subject' ? 'another-owner' : 'test-subject', 'disabled_at' => $condition === 'identity' ? now() : null]);
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors('microsoft');
    $this->assertGuest();
})->with(['account', 'identity', 'subject']);

it('revokes existing provider sessions after disablement or tenant removal', function (string $condition) {
    $user = User::factory()->create(['password' => null]);
    $identity = MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => MICROSOFT_TEST_TENANT]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    if ($condition === 'tenant') {
        config(['services.microsoft.tenants' => []]);
    } elseif ($condition === 'account') {
        $user->forceFill(['disabled_at' => now()])->save();
    } else {
        $identity->forceFill(['disabled_at' => now()])->save();
    }
    $this->get(route('dashboard'))->assertForbidden();
    $this->assertGuest();
})->with(['tenant', 'account', 'identity']);

it('blocks local recovery and credential management for provider-only accounts', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => null]);
    MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => MICROSOFT_TEST_TENANT]);
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasErrors('email');
    Notification::assertNothingSent();
    $token = Password::createToken($user);
    $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'Sample-passphrase-48!', 'password_confirmation' => 'Sample-passphrase-48!'])->assertSessionHasErrors('email');
    $this->actingAs($user)->put(route('user-password.update'), ['password' => 'Sample-passphrase-48!'])->assertForbidden();
    $this->get(route('security.edit'))->assertForbidden();
    $this->post('/user/two-factor-authentication')->assertForbidden();
    $this->get('/user/passkeys/options')->assertForbidden();
    expect($user->fresh()->password)->toBeNull();
});

it('fails closed when the provider or shared transaction store is unavailable', function (string $failure) {
    [$callback, $stub] = beginMicrosoft($this);
    if ($failure === 'provider') {
        $stub->status = 503;
    } else {
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('private cache details'));
    }
    $this->get($callback)->assertRedirectToRoute('login')->assertSessionHasErrors(['microsoft' => 'Microsoft sign-in could not be completed. Please try again.']);
    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with(['provider', 'cache']);

it('rejects missing configuration, arbitrary organizations and expired onboarding', function () {
    $this->post(route('microsoft.start', 'unknown'))->assertRedirectToRoute('login');
    $this->get(route('microsoft.contact'))->assertRedirectToRoute('login');
    config(['services.microsoft.tenants' => []]);
    $this->get(route('microsoft.index'))->assertRedirectToRoute('login');
});

it('cannot consume onboarding twice even from a restored session snapshot', function () {
    Notification::fake();
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $pending = session('microsoft.pending');
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'first@example.test'])->assertRedirectToRoute('verification.notice');
    $this->post(route('logout'))->assertRedirect();
    $this->withSession(['microsoft.pending' => $pending])->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'second@example.test'])->assertRedirectToRoute('login');
    expect(User::count())->toBe(1);
    expect(MicrosoftIdentity::count())->toBe(1);
    $this->assertGuest();
});

it('denies expired onboarding and validates required contact fields', function () {
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $pending = session('microsoft.pending');
    $this->post(route('microsoft.contact.store'), [])->assertSessionHasErrors(['name', 'email']);
    $this->withSession(['microsoft.pending' => [...$pending, 'expires_at' => now()->subSecond()->timestamp]])
        ->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])->assertRedirectToRoute('login');
    expect(User::count())->toBe(0);
});

it('requires CSRF protection before starting sign-in or submitting contact details', function () {
    app()->instance('env', 'local');
    $this->post(route('microsoft.start', 'example'))->assertStatus(419);
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])->assertStatus(419);
    expect(User::count())->toBe(0);
});

it('keeps authenticated accounts separate from provider callbacks and onboarding', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('microsoft.callback', ['code' => 'test', 'state' => 'test']))->assertRedirectToRoute('dashboard');
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])->assertRedirectToRoute('dashboard');
    expect(MicrosoftIdentity::count())->toBe(0);
    $this->assertAuthenticatedAs($user);
});

it('denies malformed configuration without exposing secrets or identities', function (array $configuration) {
    config($configuration);
    $this->get(route('login'))->assertOk();
    $this->post(route('microsoft.start', 'example'))->assertRedirectToRoute('login')->assertSessionHasErrors('microsoft');
})->with([
    [['services.microsoft.client_id' => null]],
    [['services.microsoft.client_secret' => null]],
    [['services.microsoft.tenants' => true]],
    [['services.microsoft.tenants.example.id' => MICROSOFT_TEST_CLIENT]],
    [['services.microsoft.tenants.example.id' => MicrosoftOidc::CONSUMER_TENANT]],
]);

it('admits only one simultaneous signed callback or identity registration', function (string $phase) {
    if (getenv('MOMENTO_MICROSOFT_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }
    DB::commit();
    $directory = sys_get_temp_dir().'/momento-microsoft-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $ids = [];
    $email = 'concurrent-'.bin2hex(random_bytes(8)).'@example.test';
    try {
        [$callback, $stub, $transaction] = beginMicrosoft($this);
        app(MicrosoftOidc::class)->identity(MICROSOFT_TEST_TENANT, 'test-authorization-code', $transaction);
        if ($phase === 'registration') {
            $this->get($callback)->assertRedirectToRoute('microsoft.contact');
            $this->get(route('microsoft.contact'))->assertOk();
        }
        [$token, $jwks] = microsoftToken($stub->claims);
        $initial = session()->all();
        $sessionId = session()->getId();
        $ids[] = $sessionId;
        $name = config('session.cookie');
        $encrypter = app(Encrypter::class);
        $cookies = [];
        foreach ([1, 2] as $worker) {
            if ($phase === 'registration') {
                // Distinct valid transactions must still converge on one identity owner.
                $initial['microsoft']['pending']['transaction'] = bin2hex(random_bytes(32));
                $sessionId = bin2hex(random_bytes(20));
                $ids[] = $sessionId;
            }
            DB::table('sessions')->updateOrInsert(['id' => $sessionId], [
                'user_id' => null, 'payload' => base64_encode(json_encode($initial, JSON_THROW_ON_ERROR)), 'last_activity' => now()->timestamp,
            ]);
            $cookies[$worker] = $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$sessionId, false);
        }
        $settings = config('services.microsoft');
        $tasks = [];
        foreach ([1, 2] as $worker) {
            $cookie = $cookies[$worker];
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $phase, $callback, $token, $name, $cookie, $settings, $email, $initial): array {
                config(['services.microsoft' => $settings]);
                app()->instance(MicrosoftOidc::class, new MicrosoftOidc(new Client(['handler' => new MockHandler([
                    new Response(200, ['Content-Type' => 'application/json'], json_encode(['id_token' => $token, 'token_type' => 'Bearer'])),
                ])])));
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent Microsoft request timed out.');
                    }
                    usleep(10000);
                }
                $request = Request::create($phase === 'callback' ? $callback : '/auth/microsoft/contact', $phase === 'callback' ? 'GET' : 'POST',
                    $phase === 'callback' ? [] : ['name' => 'Concurrent Example', 'email' => $email, '_token' => $initial['_token']], [$name => $cookie]);
                $kernel = app(Kernel::class);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);

                return [parse_url($response->headers->get('Location', ''), PHP_URL_PATH), $request->session()->getId(), $response->getStatusCode()];
            }, null, null);
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 20);
        $locations = array_column($results, 0);
        $ids = [...$ids, ...array_column($results, 1)];
        sort($locations);
        expect(array_column($results, 2))->toBe([302, 302]);
        if ($phase === 'callback') {
            expect($locations)->toBe(['/auth/microsoft/contact', '/login']);
        } else {
            expect(array_count_values($locations)['/email/verify'] ?? 0)->toBe(1);
            $denied = array_values(array_diff($locations, ['/email/verify']));
            expect($denied)->toHaveCount(1);
            expect($denied[0])->toBeIn(['/login', '/auth/microsoft/contact']);
        }
        expect(User::where('email', $email)->count())->toBe($phase === 'callback' ? 0 : 1);
        expect(MicrosoftIdentity::where('tenant_id', MICROSOFT_TEST_TENANT)->where('object_id', MICROSOFT_TEST_OBJECT)->count())->toBe($phase === 'callback' ? 0 : 1);
    } finally {
        DB::table('sessions')->whereIn('id', $ids)->delete();
        User::where('email', $email)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
})->with(['callback', 'registration']);

it('rejects a historical contact email collision regardless of case and guards database races', function () {
    $existing = User::factory()->create(['email' => 'existing@example.test']);
    DB::table('users')->where('id', $existing->id)->update(['email' => 'Existing@Example.Test']);
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'existing@example.test'])->assertSessionHasErrors('email');
    expect(User::count())->toBe(1);
    expect(fn () => DB::transaction(fn () => User::factory()->create(['email' => 'EXISTING@example.test'])))->toThrow(UniqueConstraintViolationException::class);
    expect(MicrosoftIdentity::count())->toBe(0);
});

it('applies the pre-login passkey authorization boundary to local and provider-only users', function () {
    $local = User::factory()->create();
    $passkey = new Passkey;
    $passkey->setRelation('user', $local);
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeTrue();
    $local->forceFill(['disabled_at' => now()]);
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeFalse();
    $provider = User::factory()->create(['password' => null]);
    $passkey->setRelation('user', $provider);
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeFalse();
});
