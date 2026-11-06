<?php

use App\Models\MicrosoftIdentity;
use App\Models\User;
use App\Notifications\MicrosoftContactVerification;
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
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

const MICROSOFT_TEST_TENANT = '11111111-1111-4111-8111-111111111111';
const MICROSOFT_TEST_CLIENT = '22222222-2222-4222-8222-222222222222';
const MICROSOFT_TEST_OBJECT = '33333333-3333-4333-8333-333333333333';
const MICROSOFT_CONTACT_STATUS = 'If this address is eligible, check its inbox for a verification link. Open it in this browser session; submit the form again if you need a fresh link.';

beforeEach(function () {
    $addressHash = hash('sha256', $this->nameWithDataSet());
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

function microsoftContactVerificationUrl(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(MicrosoftContactVerification::class, function ($notification, array $channels, $notifiable) use (&$url, $email): bool {
        $url = $notification->url;

        return $channels === ['mail'] && $notifiable->routeNotificationFor('mail') === $email;
    });

    return $url ?? throw new RuntimeException('No matching contact verification notification was captured.');
}

it('binds authorization to an approved organization with nonce and S256 and no Graph scopes', function () {
    [, , $transaction, $response] = beginMicrosoft($this);
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['scope' => 'openid profile email', 'response_type' => 'code', 'code_challenge_method' => 'S256', 'state' => $transaction['state'], 'nonce' => $transaction['nonce']]);
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $transaction['code_verifier'], true)), '+/', '-_'), '='));
    $this->get(route('microsoft.index'))->assertInertia(fn (Assert $page) => $page->component('auth/microsoft')->has('organizations', 1)->missing('client_secret')->missing('tenant_id'));
});

it('creates a verified passwordless account only after signed contact proof', function () {
    Notification::fake();
    [$callback, $stub, $transaction] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact')->assertSessionMissing('microsoft.transaction');
    $this->assertGuest();
    expect(User::count())->toBe(0);
    $this->get(route('microsoft.contact'))->assertInertia(fn (Assert $page) => $page->component('auth/microsoft-contact')->where('suggestedName', 'Alex Example')->missing('tenant_id')->missing('subject')->missing('id_token'));
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex Example', 'email' => ' Contact@Example.Test '])
        ->assertRedirectToRoute('microsoft.contact')
        ->assertSessionHas('status', MICROSOFT_CONTACT_STATUS);
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);
    $url = microsoftContactVerificationUrl('contact@example.test');
    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('auth/microsoft-contact-confirm')->where('email', 'contact@example.test'));
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);
    $this->post($url)->assertRedirectToRoute('dashboard');
    $user = User::sole();
    expect($user->email)->toBe('contact@example.test');
    expect($user->password)->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
    expect(MicrosoftIdentity::sole()->user_id)->toBe($user->id);
    Notification::assertNotSentTo($user, VerifyEmail::class);
    $this->get(route('dashboard'))->assertOk();
    $this->post(route('logout'))->assertRedirect();
    $this->get($url)->assertRedirectToRoute('login');
    expect(User::count())->toBe(1);
    expect(MicrosoftIdentity::count())->toBe(1);
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
    $requests = count($stub->requests);
    $this->get(route('microsoft.callback', ['state' => 'wrong', 'code' => 'test']))->assertRedirectToRoute('login');
    expect($stub->requests)->toHaveCount($requests);
    [$callback] = beginMicrosoft($this);
    $this->get($callback.'&error=access_denied&error_description=private-provider-error')->assertRedirectToRoute('login')->assertSessionHasErrors(['microsoft' => 'Microsoft sign-in was cancelled or declined. You can try again.']);
});

it('returns a neutral response for a contact email collision without sending mail', function () {
    $existing = User::factory()->create(['email' => 'existing@example.test']);
    $originalPassword = $existing->password;
    Notification::fake();
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');

    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'separate@example.test'])
        ->assertRedirectToRoute('microsoft.contact')
        ->assertSessionHas('status', MICROSOFT_CONTACT_STATUS);
    $proofUrl = microsoftContactVerificationUrl('separate@example.test');
    $pending = session('microsoft.pending');

    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'existing@example.test'])
        ->assertRedirectToRoute('microsoft.contact')
        ->assertSessionHas('status', MICROSOFT_CONTACT_STATUS);

    expect(session('microsoft.pending.contact.email'))->toBe('separate@example.test')
        ->and(session('microsoft.pending.proof'))->toBe($pending['proof']);
    Notification::assertSentOnDemandTimes(MicrosoftContactVerification::class, 1);

    $this->get($proofUrl)->assertInertia(fn (Assert $page) => $page->component('auth/microsoft-contact-confirm'));
    $this->post($proofUrl)->assertRedirectToRoute('dashboard');

    $providerUser = User::where('email', 'separate@example.test')->sole();
    expect($providerUser->password)->toBeNull()
        ->and($providerUser->email_verified_at)->not->toBeNull()
        ->and($existing->fresh()->password)->toBe($originalPassword)
        ->and(MicrosoftIdentity::where('user_id', $providerUser->id)->count())->toBe(1);
    expect($existing->fresh()->email)->toBe('existing@example.test');
});

it('does not reserve an address before proof so its owner can register locally', function () {
    Notification::fake();
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $this->post(route('microsoft.contact.store'), ['name' => 'Attacker-chosen name', 'email' => 'victim@example.test'])
        ->assertRedirectToRoute('microsoft.contact');
    $proofUrl = microsoftContactVerificationUrl('victim@example.test');
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);

    $this->postJson(route('register.store'), [
        'name' => 'Address owner',
        'email' => 'victim@example.test',
        'password' => 'Sample-passphrase-48!',
        'password_confirmation' => 'Sample-passphrase-48!',
    ])->assertAccepted();
    $owner = User::where('email', 'victim@example.test')->sole();
    $originalPassword = $owner->password;

    $this->get($proofUrl)->assertInertia(fn (Assert $page) => $page->component('auth/microsoft-contact-confirm'));
    $this->post($proofUrl)->assertRedirectToRoute('microsoft.contact')->assertSessionHas('status');

    $this->assertGuest();
    expect(User::count())->toBe(1);
    expect($owner->fresh()->password)->toBe($originalPassword);
    expect(MicrosoftIdentity::count())->toBe(0);
});

it('binds proof to the same pending Microsoft session and rejects expired or replaced links', function () {
    Notification::fake();
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'first@example.test'])
        ->assertRedirectToRoute('microsoft.contact');
    $oldUrl = microsoftContactVerificationUrl('first@example.test');
    $pending = session('microsoft.pending');

    $this->withSession(['microsoft.pending' => []])->post($oldUrl)->assertRedirectToRoute('login');
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);

    $this->withSession(['microsoft.pending' => $pending]);
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'second@example.test'])
        ->assertRedirectToRoute('microsoft.contact');
    $newUrl = microsoftContactVerificationUrl('second@example.test');
    $this->post($oldUrl)->assertRedirectToRoute('login');
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);

    $this->get($newUrl)->assertInertia(fn (Assert $page) => $page->component('auth/microsoft-contact-confirm'));
    $this->travel(11)->minutes();
    $this->post($newUrl)->assertForbidden();
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);
});

it('keeps provider-only onboarding unpersisted when verification mail fails', function () {
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
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');

    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'contact@example.test'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);
    expect(session('microsoft.pending.contact.email'))->toBe('contact@example.test');
    Exceptions::assertReported(TransportException::class);
});

it('does not reuse an identity that became owned before contact proof', function () {
    Notification::fake();
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $identityOwner = User::factory()->create(['password' => null]);
    MicrosoftIdentity::factory()->create([
        'user_id' => $identityOwner->id,
        'tenant_id' => MICROSOFT_TEST_TENANT,
        'object_id' => MICROSOFT_TEST_OBJECT,
        'subject' => 'test-subject',
    ]);

    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'separate@example.test'])
        ->assertRedirectToRoute('microsoft.contact')
        ->assertSessionHas('status');

    $this->assertGuest();
    expect(User::count())->toBe(1);
    expect(MicrosoftIdentity::count())->toBe(1);
    Notification::assertNothingSent();
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
    $this->withHeaders([
        'Accept' => 'text/html, application/xhtml+xml',
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
    ])->get(route('dashboard'))
        ->assertForbidden()
        ->assertJsonPath('component', 'access-denied')
        ->assertJsonPath('clearHistory', true)
        ->assertJsonPath('encryptHistory', true)
        ->assertJsonPath('props.auth.user', null);
    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirectToRoute('login');
})->with(['tenant', 'account', 'identity']);

it('keeps forced signout JSON denials as JSON 403 responses', function () {
    $user = User::factory()->create(['disabled_at' => now()]);
    $this->actingAs($user)
        ->withHeader('Accept', 'application/json')
        ->get(route('dashboard'))
        ->assertForbidden()
        ->assertJsonMissingPath('component');

    $this->assertGuest();
});

it('blocks local recovery and credential management for provider-only accounts', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => null]);
    MicrosoftIdentity::factory()->create(['user_id' => $user->id, 'tenant_id' => MICROSOFT_TEST_TENANT]);
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', 'If an eligible account exists, check its inbox for a reset link. If it does not arrive, wait a minute and try again.');
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
        $store = app('cache')->store();
        Cache::shouldReceive('driver')->andReturn($store);
        Cache::shouldReceive('store')->andReturn($store);
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
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'first@example.test'])
        ->assertRedirectToRoute('microsoft.contact');
    $proofUrl = microsoftContactVerificationUrl('first@example.test');
    expect(User::count())->toBe(0);
    expect(MicrosoftIdentity::count())->toBe(0);
    $this->post($proofUrl)->assertRedirectToRoute('dashboard');
    $this->post(route('logout'))->assertRedirect();
    $this->withSession(['microsoft.pending' => $pending])->post($proofUrl)->assertRedirectToRoute('login');
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
    $proofUrl = URL::temporarySignedRoute('microsoft.contact.verify', now()->addMinute(), ['proof' => 'test-proof']);
    $this->post($proofUrl)->assertStatus(419);
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

it('admits only one simultaneous signed callback or contact proof', function (string $phase) {
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
        $proofUrl = null;
        if ($phase === 'proof') {
            Notification::fake();
            $this->get($callback)->assertRedirectToRoute('microsoft.contact');
            $this->post(route('microsoft.contact.store'), ['name' => 'Concurrent Example', 'email' => $email])
                ->assertRedirectToRoute('microsoft.contact');
            $proofUrl = microsoftContactVerificationUrl($email);
        }
        [$token, $jwks] = microsoftToken($stub->claims);
        $initial = session()->all();
        $sessionId = session()->getId();
        $ids[] = $sessionId;
        $name = config('session.cookie');
        $encrypter = app(Encrypter::class);
        $cookies = [];
        foreach ([1, 2] as $worker) {
            if ($phase === 'proof') {
                // Distinct sessions replaying one proof must still create a single account.
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
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $phase, $callback, $proofUrl, $token, $name, $cookie, $settings): array {
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
                $request = Request::create($phase === 'callback' ? $callback : $proofUrl, $phase === 'callback' ? 'GET' : 'POST', [], [$name => $cookie]);
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
            expect($locations)->toBe(['/dashboard', '/login']);
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
})->with(['callback', 'proof']);

it('serializes a slow contact GET with a contact update for the same session', function () {
    if (getenv('MOMENTO_MICROSOFT_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }

    DB::commit();
    Notification::fake();
    $directory = sys_get_temp_dir().'/momento-microsoft-session-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $ids = [];
    $oldEmail = 'before-'.bin2hex(random_bytes(8)).'@example.test';
    $newEmail = 'after-'.bin2hex(random_bytes(8)).'@example.test';

    try {
        [$callback] = beginMicrosoft($this);
        $this->get($callback)->assertRedirectToRoute('microsoft.contact');
        $this->post(route('microsoft.contact.store'), ['name' => 'Concurrent Example', 'email' => $oldEmail])
            ->assertRedirectToRoute('microsoft.contact');
        $oldProofUrl = microsoftContactVerificationUrl($oldEmail);
        $oldProof = session('microsoft.pending.proof');

        $sessionId = session()->getId();
        $ids[] = $sessionId;
        $sessionName = config('session.cookie');
        $encrypter = app(Encrypter::class);
        $cookie = $encrypter->encrypt(CookieValuePrefix::create($sessionName, $encrypter->getKey()).$sessionId, false);
        DB::table('sessions')->updateOrInsert(['id' => $sessionId], [
            'user_id' => null,
            'payload' => base64_encode(json_encode(session()->all(), JSON_THROW_ON_ERROR)),
            'last_activity' => now()->timestamp,
        ]);
        $settings = config('services.microsoft');

        $get = Closure::bind(static function () use ($directory, $sessionName, $cookie, $settings): array {
            config(['services.microsoft' => $settings]);
            app()->instance(MicrosoftOidc::class, new class($directory) extends MicrosoftOidc
            {
                public function __construct(private string $directory)
                {
                    parent::__construct(new Client);
                }

                public function allowsTenant(string $tenant): bool
                {
                    touch($this->directory.'/get-loaded');
                    $deadline = microtime(true) + 10;
                    while (! file_exists($this->directory.'/post-start')) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Contact GET did not observe the concurrent POST.');
                        }
                        usleep(10000);
                    }
                    usleep(1200000);

                    return true;
                }
            });

            $request = Request::create('/auth/microsoft/contact', 'GET', [], [$sessionName => $cookie]);
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return [$response->getStatusCode(), $response->headers->get('Location')];
        }, null, null);

        $post = Closure::bind(static function () use ($directory, $sessionName, $cookie, $settings, $newEmail): array {
            config(['services.microsoft' => $settings]);
            app()->instance(MicrosoftOidc::class, new MicrosoftOidc(new Client));
            $deadline = microtime(true) + 10;
            while (! file_exists($directory.'/get-loaded')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Contact POST did not observe the loaded GET session.');
                }
                usleep(10000);
            }
            touch($directory.'/post-start');

            $request = Request::create('/auth/microsoft/contact', 'POST', [
                'name' => 'Concurrent Example', 'email' => $newEmail,
            ], [$sessionName => $cookie]);
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return [$response->getStatusCode(), parse_url($response->headers->get('Location', ''), PHP_URL_PATH)];
        }, null, null);

        $results = Concurrency::driver('process')->run([$get, $post], timeout: 20);
        expect($results[0][0])->toBe(200)
            ->and($results[1])->toBe([302, '/auth/microsoft/contact']);

        $payload = DB::table('sessions')->where('id', $sessionId)->value('payload');
        $saved = json_decode(base64_decode($payload, true), true, 512, JSON_THROW_ON_ERROR);
        $pending = $saved['microsoft']['pending'];
        expect($pending['contact']['email'])->toBe($newEmail)
            ->and($pending['proof'])->not->toBe($oldProof);

        $kernel = app(Kernel::class);
        $oldRequest = Request::create($oldProofUrl, 'GET', [], [$sessionName => $cookie]);
        $oldResponse = $kernel->handle($oldRequest);
        $kernel->terminate($oldRequest, $oldResponse);
        expect($oldResponse->getStatusCode())->toBe(302)
            ->and(parse_url($oldResponse->headers->get('Location', ''), PHP_URL_PATH))->toBe('/login');

        $currentProofUrl = URL::temporarySignedRoute(
            'microsoft.contact.verify', now()->addMinutes(1), ['proof' => $pending['proof']],
        );
        $confirmRequest = Request::create($currentProofUrl, 'GET', [], [$sessionName => $cookie]);
        $confirmResponse = $kernel->handle($confirmRequest);
        $kernel->terminate($confirmRequest, $confirmResponse);
        expect($confirmResponse->getStatusCode())->toBe(200)
            ->and(User::where('email', $newEmail)->count())->toBe(0)
            ->and(MicrosoftIdentity::where('tenant_id', MICROSOFT_TEST_TENANT)->where('object_id', MICROSOFT_TEST_OBJECT)->count())->toBe(0);

        $completeRequest = Request::create($currentProofUrl, 'POST', [], [$sessionName => $cookie]);
        $completeResponse = $kernel->handle($completeRequest);
        $kernel->terminate($completeRequest, $completeResponse);
        expect($completeResponse->getStatusCode())->toBe(302)
            ->and(parse_url($completeResponse->headers->get('Location', ''), PHP_URL_PATH))->toBe('/dashboard')
            ->and(User::where('email', $newEmail)->count())->toBe(1)
            ->and(User::where('email', $oldEmail)->count())->toBe(0)
            ->and(MicrosoftIdentity::where('tenant_id', MICROSOFT_TEST_TENANT)->where('object_id', MICROSOFT_TEST_OBJECT)->count())->toBe(1);
    } finally {
        DB::table('sessions')->whereIn('id', $ids)->delete();
        User::whereIn('email', [$oldEmail, $newEmail])->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});

it('rejects a historical contact email collision regardless of case and guards database races', function () {
    $existing = User::factory()->create(['email' => 'existing@example.test']);
    DB::table('users')->where('id', $existing->id)->update(['email' => 'Existing@Example.Test']);
    [$callback] = beginMicrosoft($this);
    $this->get($callback)->assertRedirectToRoute('microsoft.contact');
    $this->post(route('microsoft.contact.store'), ['name' => 'Alex', 'email' => 'existing@example.test'])
        ->assertRedirectToRoute('microsoft.contact')
        ->assertSessionHas('status');
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
