<?php

use App\Actions\ManagePhotoPosts;
use App\Enums\PhotoPostFailure;
use App\Exceptions\PhotoDecoderException;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('admits no more than four concurrent accounts into staging on PostgreSQL', function () {
    if (getenv('MOMENTO_F07_CONCURRENCY') !== '1' || config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly enabled isolated PostgreSQL concurrency fixture.');
    }

    $users = User::factory()->count(5)->create();
    $userIds = $users->modelKeys();
    DB::commit();

    $directory = rtrim((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR).'/momento-photo-post-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $inputPath = $directory.'/synthetic-input.jpg';
    file_put_contents($inputPath, 'synthetic bounded source bytes');
    $launcher = $directory.'/fixed-launcher';
    file_put_contents($launcher, '#!'.PHP_BINARY."\n<?php exit(0);\n");
    chmod($launcher, 0700);
    config(['photo_processing.decoder_executable' => $launcher]);

    try {
        $tasks = [];
        foreach ($userIds as $userId) {
            $tasks[] = Closure::bind(static function () use ($directory, $inputPath, $userId, $launcher): string {
                config(['photo_processing.decoder_executable' => $launcher]);
                touch($directory.'/ready-'.$userId);
                $deadline = microtime(true) + 15;
                while (count(glob($directory.'/ready-*') ?: []) < 5) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Photo admission race barrier timed out.');
                    }
                    usleep(10000);
                }

                try {
                    app(ManagePhotoPosts::class)->submit(
                        User::query()->findOrFail($userId),
                        new UploadedFile($inputPath, 'synthetic.jpg', 'image/jpeg', null, true),
                        'Synthetic concurrency probe',
                        (string) Str::uuid(),
                    );

                    return 'admitted';
                } catch (PhotoDecoderException $exception) {
                    return $exception->failure === PhotoPostFailure::CapacityReached ? 'capacity' : 'unexpected:'.$exception->failure->value;
                } catch (ValidationException) {
                    return 'per-account-active';
                } catch (Throwable $exception) {
                    return 'unexpected:'.class_basename($exception);
                }
            }, null, null);
        }

        $results = Concurrency::driver('process')->run($tasks, timeout: 45);
        expect(array_count_values($results))->toBe(['admitted' => 4, 'capacity' => 1]);
        expect(PhotoPost::whereIn('user_id', $userIds)->count())->toBe(4)
            ->and(PhotoPost::whereIn('user_id', $userIds)->sum('staging_bytes_charged'))
            ->toBeLessThanOrEqual(config('photo_processing.max_staging_bytes'));
    } finally {
        $posts = PhotoPost::query()->whereIn('user_id', $userIds)->get();
        foreach ($posts as $post) {
            Storage::disk('photo_posts')->deleteDirectory(dirname($post->raw_key));
            Storage::disk('photo_posts')->delete('.locks/'.$post->id.'.guard');
        }
        PhotoPost::query()->whereIn('user_id', $userIds)->delete();
        DB::table('jobs')->where('queue', 'photo-processing')->delete();
        User::query()->whereKey($userIds)->delete();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
        DB::beginTransaction();
    }
});
