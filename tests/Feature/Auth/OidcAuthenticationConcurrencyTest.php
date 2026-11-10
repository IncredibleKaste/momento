<?php

use App\Models\OidcIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

it('creates at most one account and identity for concurrent case-insensitive contact emails', function () {
    if (getenv('MOMENTO_OIDC_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the isolated PostgreSQL concurrency fixture.');
    }
    DB::commit();
    $directory = sys_get_temp_dir().'/momento-oidc-race-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $email = 'race-'.bin2hex(random_bytes(8)).'@example.test';
    try {
        $tasks = [];
        foreach ([1, 2] as $worker) {
            $tasks[] = Closure::bind(static function () use ($directory, $worker, $email): string {
                touch($directory.'/'.$worker);
                $deadline = microtime(true) + 10;
                while (count(glob($directory.'/*')) < 2) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('OIDC account race timed out.');
                    }
                    usleep(10000);
                }
                try {
                    DB::transaction(function () use ($email, $worker): void {
                        $userId = DB::table('users')->insertGetId([
                            'name' => 'Race Example',
                            'email' => $worker === 1 ? $email : strtoupper($email),
                            'password' => null,
                            'email_verified_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        DB::table('oidc_identities')->insert([
                            'user_id' => $userId,
                            'provider' => 'google',
                            'issuer' => 'https://accounts.google.com',
                            'subject' => 'same-concurrent-subject',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });

                    return 'created';
                } catch (UniqueConstraintViolationException) {
                    return 'duplicate';
                }
            }, null, null);
        }
        $results = Concurrency::driver('process')->run($tasks, timeout: 20);

        expect(collect($results)->countBy()->sortKeys()->all())->toBe(['created' => 1, 'duplicate' => 1]);
        expect(DB::table('users')->whereRaw('lower(email) = ?', [$email])->count())->toBe(1)
            ->and(OidcIdentity::where('provider', 'google')->where('subject', 'same-concurrent-subject')->count())->toBe(1);
    } finally {
        DB::table('users')->whereRaw('lower(email) = ?', [$email])->delete();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});
