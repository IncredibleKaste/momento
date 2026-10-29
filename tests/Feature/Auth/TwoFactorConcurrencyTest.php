<?php

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

test('simultaneous challenges consume each recovery or authenticator code only once', function () {
    if (getenv('MOMENTO_TWO_FACTOR_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated PostgreSQL concurrency fixture.');
    }

    // Child HTTP kernels need committed fixtures, outside RefreshDatabase's transaction.
    DB::commit();
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);
    $directory = sys_get_temp_dir().'/momento-factor-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $sessionIds = [];
    expect(config('session.driver'))->toBe('database');

    try {
        foreach (['recovery_code' => 'recovery-code-1', 'code' => app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP')] as $field => $code) {
            $tasks = [];

            foreach (range(1, 2) as $worker) {
                $sessionId = bin2hex(random_bytes(20));
                $sessionIds[] = $sessionId;
                DB::table('sessions')->insert([
                    'id' => $sessionId, 'user_id' => null,
                    'payload' => base64_encode(json_encode(['login' => ['id' => $user->id]], JSON_THROW_ON_ERROR)),
                    'last_activity' => now()->timestamp,
                ]);
                $name = config('session.cookie');
                $encrypter = app(Encrypter::class);
                $cookie = $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$sessionId, false);

                $tasks[] = Closure::bind(static function () use ($directory, $worker, $field, $code, $name, $cookie): int {
                    touch($directory.'/'.$field.'-'.$worker);
                    $deadline = microtime(true) + 10;
                    while (count(glob($directory.'/'.$field.'-*')) < 2) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Concurrent challenge start timed out.');
                        }
                        usleep(10000);
                    }

                    // Hold the update briefly so an unprotected second read overlaps it.
                    DB::connection()->beforeExecuting(function (string $query): void {
                        if (str_starts_with($query, 'update "users"') && str_contains($query, 'two_factor_recovery_codes')) {
                            usleep(250000);
                        }
                    });

                    $request = Request::create('/two-factor-challenge', 'POST', [$field => $code], [$name => $cookie], [], ['HTTP_ACCEPT' => 'application/json']);
                    $kernel = app(Kernel::class);
                    $response = $kernel->handle($request);
                    $kernel->terminate($request, $response);

                    return $response->getStatusCode();
                }, null, null);
            }

            $statuses = Concurrency::driver('process')->run($tasks, timeout: 15);
            sort($statuses);
            expect($statuses)->toBe([204, 422]);
        }

        expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-1');
    } finally {
        DB::table('sessions')->whereIn('id', $sessionIds)->orWhere('user_id', $user->id)->delete();
        $user->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});
