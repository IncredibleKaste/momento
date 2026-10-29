<?php

use App\Models\User;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use ParagonIE\ConstantTime\Base64UrlSafe;

beforeEach(function () {
    config(['passkeys.relying_party_id' => 'localhost', 'passkeys.allowed_origins' => ['https://localhost']]);
    $addressHash = hash('sha256', $this->name());
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:'.substr($addressHash, 0, 4).':'.substr($addressHash, 4, 4).'::1']);
});

function passkeyTestDevice(): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $details = openssl_pkey_get_details($key);
    $cose = MapObject::create()
        ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
        ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
        ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
        ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($details['ec']['x']))
        ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($details['ec']['y']));

    return ['key' => $key, 'id' => random_bytes(32), 'cose' => (string) $cose];
}

function passkeyTestRegistration(array $options, array $device, string $origin = 'https://localhost', string $rp = 'localhost', int $flags = 0x45): array
{
    $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false], JSON_THROW_ON_ERROR);
    $authData = hash('sha256', $rp, true).chr($flags).pack('N', 0).str_repeat("\0", 16).pack('n', strlen($device['id'])).$device['id'].$device['cose'];
    $attestation = MapObject::create()
        ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
        ->add(TextStringObject::create('authData'), ByteStringObject::create($authData))
        ->add(TextStringObject::create('attStmt'), MapObject::create());

    return ['id' => Base64UrlSafe::encodeUnpadded($device['id']), 'rawId' => Base64UrlSafe::encodeUnpadded($device['id']), 'type' => 'public-key', 'response' => [
        'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData), 'attestationObject' => Base64UrlSafe::encodeUnpadded((string) $attestation), 'transports' => ['internal'],
    ]];
}

function passkeyTestAssertion(array $options, array $device, string $userHandle, string $origin = 'https://localhost', string $rp = 'localhost', int $flags = 0x05): array
{
    $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false], JSON_THROW_ON_ERROR);
    $authData = hash('sha256', $rp, true).chr($flags).pack('N', 0);
    openssl_sign($authData.hash('sha256', $clientData, true), $signature, $device['key'], OPENSSL_ALGO_SHA256);

    return ['id' => Base64UrlSafe::encodeUnpadded($device['id']), 'rawId' => Base64UrlSafe::encodeUnpadded($device['id']), 'type' => 'public-key', 'response' => [
        'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData), 'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData), 'signature' => Base64UrlSafe::encodeUnpadded($signature), 'userHandle' => $userHandle,
    ]];
}

test('the owner can enroll a real signed passkey and sign in with user verification', function () {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $credential = passkeyTestRegistration($options, $device);
    $this->postJson(route('passkey.store'), ['name' => 'Sample device', 'credential' => $credential])->assertOk();
    expect($user->passkeys()->sole()->name)->toBe('Sample device');
    $this->post(route('logout'));

    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $assertion = passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()));
    $this->postJson(route('passkey.login'), ['credential' => $assertion])->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('expired registration cannot add a credential even when its signature is valid', function () {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->travel(61)->seconds();

    $this->postJson(route('passkey.store'), ['name' => 'Expired device', 'credential' => passkeyTestRegistration($options, $device)])
        ->assertUnprocessable()->assertJsonValidationErrors('credential');
    expect($user->passkeys()->count())->toBe(0);
});

test('registration rejects an invalid origin relying party or missing user verification', function (string $origin, string $rp, int $flags) {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');

    $this->postJson(route('passkey.store'), ['name' => 'Rejected device', 'credential' => passkeyTestRegistration($options, passkeyTestDevice(), $origin, $rp, $flags)])
        ->assertUnprocessable();
    expect($user->passkeys()->count())->toBe(0);
})->with([
    ['https://other.example.test', 'localhost', 0x45],
    ['https://localhost', 'other.example.test', 0x45],
    ['https://localhost', 'localhost', 0x41],
]);

test('signed assertions reject a wrong origin relying party or absent user verification', function (string $origin, string $rp, int $flags) {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Sample device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
    $this->post(route('logout'));
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

    $this->postJson(route('passkey.login'), ['credential' => passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()), $origin, $rp, $flags)])
        ->assertUnprocessable();
    $this->assertGuest();
    expect($user->passkeys()->sole()->last_used_at)->toBeNull();
})->with([
    ['https://other.example.test', 'localhost', 0x05],
    ['https://localhost', 'other.example.test', 0x05],
    ['https://localhost', 'localhost', 0x01],
]);

test('a stale session cannot replay a successful zero counter passkey assertion', function () {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Sample device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
    $this->post(route('logout'));
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $staleSession = session()->all();
    $sessionId = session()->getId();
    $credential = passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()));
    $this->postJson(route('passkey.login'), ['credential' => $credential])->assertOk();
    $this->post(route('logout'));

    session()->setId($sessionId);
    $this->withSession($staleSession)->postJson(route('passkey.login'), ['credential' => $credential])->assertUnprocessable();
    $this->assertGuest();
});

test('expired sign in cannot authenticate with a correctly signed credential', function () {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Sample device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
    $this->post(route('logout'));
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $this->travel(61)->seconds();

    $this->postJson(route('passkey.login'), ['credential' => passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()))])
        ->assertUnprocessable();
    $this->assertGuest();
});

test('a modified signature cannot authenticate with an otherwise valid challenge', function () {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Sample device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
    $this->post(route('logout'));
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $credential = passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()));
    $signature = Base64UrlSafe::decodeNoPadding($credential['response']['signature']);
    $signature[strlen($signature) - 1] = $signature[strlen($signature) - 1] ^ "\x01";
    $credential['response']['signature'] = Base64UrlSafe::encodeUnpadded($signature);
    $this->postJson(route('passkey.login'), ['credential' => $credential])->assertUnprocessable();
    $this->assertGuest();
});

test('a replay-store outage cannot complete passkey authentication', function () {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Sample device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
    $this->post(route('logout'));
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    Cache::extend('failing-passkey', fn () => Cache::repository(new class extends ArrayStore
    {
        public function add($key, $value, $seconds = 0): bool
        {
            if (str_starts_with($key, 'momento.passkey.')) {
                throw new RuntimeException('Replay store unavailable.');
            }

            return parent::add($key, $value, $seconds);
        }
    }));
    config(['cache.default' => 'failing-passkey']);
    $this->postJson(route('passkey.login'), ['credential' => passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()))])->assertStatus(500);
    $this->assertGuest();
    expect($user->passkeys()->sole()->last_used_at)->toBeNull();
});

test('a passkey confirms only its owner and a login challenge cannot confirm an account', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Owner device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
    $this->withSession(['auth.password_confirmed_at' => 0]);
    $options = $this->getJson(route('passkey.confirm-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.confirm'), ['credential' => passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($owner->getPasskeyUserHandle()))])
        ->assertOk()->assertSessionHas('auth.password_confirmed_at', now()->timestamp);

    $this->actingAs($other)->withSession(['auth.password_confirmed_at' => 0]);
    $options = $this->getJson(route('passkey.confirm-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.confirm'), ['credential' => passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($owner->getPasskeyUserHandle()))])
        ->assertUnprocessable()->assertSessionHas('auth.password_confirmed_at', 0);

    $this->post(route('logout'));
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0]);
    $this->postJson(route('passkey.confirm'), ['credential' => passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($owner->getPasskeyUserHandle()))])
        ->assertUnprocessable()->assertSessionHas('auth.password_confirmed_at', 0);
});

test('credentialless identities cannot manage passkeys or sign in with an existing local passkey', function () {
    $user = User::factory()->create();
    $device = passkeyTestDevice();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Sample device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
    DB::table('users')->where('id', $user->id)->update(['password' => '']);
    $this->getJson(route('passkey.registration-options'))->assertForbidden();
    $this->getJson(route('passkey.confirm-options'))->assertForbidden();
    $this->post(route('logout'));
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.login'), ['credential' => passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()))])->assertUnprocessable();
    $this->assertGuest();
});

test('names and removal are scoped to the owner and removing the last passkey preserves password fallback', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
    $this->postJson(route('passkey.store'), ['name' => 'Owner device', 'user_id' => $other->id, 'credential' => passkeyTestRegistration($options, passkeyTestDevice())])->assertOk();
    $passkey = $owner->passkeys()->sole();
    expect($other->passkeys()->count())->toBe(0);
    $this->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page->where('passkeys.0.name', 'Owner device')->missing('passkeys.0.credential')->missing('passkeys.0.credential_id'));

    $this->actingAs($other)->deleteJson(route('passkey.destroy', $passkey))->assertForbidden();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])
        ->deleteJson(route('passkey.destroy', $passkey))->assertStatus(423);
    expect($owner->passkeys()->count())->toBe(1);
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->deleteJson(route('passkey.destroy', $passkey))->assertOk();
    expect($owner->passkeys()->count())->toBe(0);
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($owner);
});

test('malformed credentials return validation errors and cannot create a partial enrollment', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $this->getJson(route('passkey.registration-options'))->assertOk();
    $this->postJson(route('passkey.store'), ['name' => 'Incomplete device', 'credential' => ['id' => ['invalid']]])
        ->assertUnprocessable()->assertJsonValidationErrors('credential.id');
    expect($user->passkeys()->count())->toBe(0);
});

test('passkey option requests are rate limited within a session', function () {
    $this->withSession([])->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    foreach (range(1, 10) as $attempt) {
        $this->getJson(route('passkey.login-options'))->assertOk();
    }
    $this->getJson(route('passkey.login-options'))->assertTooManyRequests();
});

test('rotating guest sessions cannot bypass the passkey per IP limit', function () {
    foreach (range(1, 60) as $attempt) {
        $this->getJson(route('passkey.login-options'))->assertOk();
    }
    $this->getJson(route('passkey.login-options'))->assertTooManyRequests();
});

test('mutating passkey requests require CSRF protection', function (string $route) {
    $this->app->instance('env', 'local');
    $this->postJson(route($route))->assertStatus(419);
})->with(['passkey.login', 'passkey.store', 'passkey.confirm']);

test('passkey removal requires CSRF protection', function () {
    $this->app->instance('env', 'local');
    $this->deleteJson(route('passkey.destroy', 1))->assertStatus(419);
});

test('simultaneous requests cannot consume the same signed passkey challenge twice', function () {
    if (getenv('MOMENTO_PASSKEY_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }

    // Child HTTP kernels need committed fixtures outside RefreshDatabase's transaction.
    DB::commit();
    $user = User::factory()->create();
    $directory = sys_get_temp_dir().'/momento-passkey-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $sessionId = null;

    try {
        $device = passkeyTestDevice();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $registration = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');
        $this->postJson(route('passkey.store'), ['name' => 'Concurrent device', 'credential' => passkeyTestRegistration($registration, $device)])->assertOk();
        $this->post(route('logout'));
        $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');
        $credential = passkeyTestAssertion($options, $device, Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()));
        $sessionId = session()->getId();
        // Persist the test kernel's pending session for independent HTTP workers.
        DB::table('sessions')->updateOrInsert(['id' => $sessionId], [
            'user_id' => null, 'payload' => base64_encode(json_encode(session()->all(), JSON_THROW_ON_ERROR)), 'last_activity' => now()->timestamp,
        ]);
        $name = config('session.cookie');
        $encrypter = app(Encrypter::class);
        $cookie = $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$sessionId, false);
        $ceremonyConfig = ['passkeys.relying_party_id' => config('passkeys.relying_party_id'), 'passkeys.allowed_origins' => config('passkeys.allowed_origins')];
        $tasks = [];

        foreach (range(1, 2) as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $credential, $name, $cookie, $ceremonyConfig): int {
                config($ceremonyConfig);
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Concurrent passkey start timed out.');
                    }
                    usleep(10000);
                }
                $request = Request::create('/passkeys/login', 'POST', ['credential' => $credential], [$name => $cookie], [], ['HTTP_ACCEPT' => 'application/json']);
                $kernel = app(Kernel::class);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);

                return $response->getStatusCode();
            }, null, null);
        }

        $statuses = Concurrency::driver('process')->run($tasks, timeout: 15);
        sort($statuses);
        expect($statuses)->toBe([200, 422]);
    } finally {
        DB::table('sessions')->where('id', $sessionId)->orWhere('user_id', $user->id)->delete();
        $user->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});
