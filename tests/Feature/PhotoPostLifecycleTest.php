<?php

use App\Actions\ManagePhotoPosts;
use App\Enums\PhotoPostFailure;
use App\Enums\PhotoPostStatus;
use App\Exceptions\PhotoDecoderException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ProcessPhotoPost;
use App\Models\PhotoPost;
use App\Models\User;
use App\Services\PhotoDecoder;
use App\Services\WebpOutputInspector;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $directory = storage_path('framework/testing/photo-decoder-'.Str::random(12));
    mkdir($directory, 0700, true);
    $this->decoderDirectory = $directory;

    $payload = "\0\0\0\x9d\x01\x2a\x01\0\x01\0";
    $webp = 'RIFF'.pack('V', 22).'WEBP'.'VP8 '.pack('V', strlen($payload)).$payload;
    $script = $directory.'/decoder';
    $encoded = var_export(base64_encode($webp), true);
    $program = <<<'PHP'
<?php
if ($argc !== 1 || getenv('APP_KEY') !== false) { exit(66); }
stream_get_contents(STDIN);
fwrite(STDERR, 'decoder detail must stay private');
fwrite(STDOUT, base64_decode(__OUTPUT__));
PHP;
    file_put_contents($script, '#!'.PHP_BINARY."\n".str_replace('__OUTPUT__', $encoded, $program)."\n");
    chmod($script, 0700);
    config(['photo_processing.decoder_executable' => $script]);
    Storage::fake('photo_posts');
    Queue::fake();
});

afterEach(function () {
    foreach (glob($this->decoderDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->decoderDirectory);
});

function photoPostUpload(string $caption = 'A quiet morning'): array
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//2Q==');

    return [
        'photo' => UploadedFile::fake()->createWithContent('camera.jpg', $jpeg),
        'caption' => $caption,
    ];
}

/** @param list<array{string, string}> $chunks */
function syntheticWebpWithChunks(array $chunks): string
{
    $body = 'WEBP';
    foreach ($chunks as [$kind, $payload]) {
        $body .= $kind.pack('V', strlen($payload)).$payload;
        if (strlen($payload) % 2 === 1) {
            $body .= "\0";
        }
    }

    return 'RIFF'.pack('V', strlen($body)).$body;
}

function setPhotoDecoderOutput(string $directory, string $output): void
{
    $script = $directory.'/decoder';
    $program = "<?php\nstream_get_contents(STDIN);\nfwrite(STDOUT, ".var_export($output, true).");\n";
    file_put_contents($script, '#!'.PHP_BINARY."\n".$program);
    chmod($script, 0700);
}

it('stores one private staging object and reuses or conflicts on the idempotency key', function () {
    $owner = User::factory()->create();
    $key = (string) Str::uuid();
    $payload = photoPostUpload();
    $payload['idempotency_key'] = $key;

    $first = $this->actingAs($owner)->post(route('photo-posts.store'), $payload)->assertRedirect();
    $post = PhotoPost::sole();
    expect($first->headers->get('Location'))->toBe(route('photo-posts.show', $post));
    expect($post->status)->toBe(PhotoPostStatus::Queued)
        ->and($post->staging_bytes_charged)->toBeGreaterThan(0)
        ->and($post->raw_key)->toMatch('/^[0-9a-f-]{36}\/original$/');
    Storage::disk('photo_posts')->assertExists($post->raw_key);
    Queue::assertPushed(ProcessPhotoPost::class, fn (ProcessPhotoPost $job): bool => $job->photoPostId === $post->id
        && $job->attemptToken === $post->attempt_token);

    $retry = photoPostUpload();
    $retry['idempotency_key'] = $key;
    $this->post(route('photo-posts.store'), $retry)->assertRedirect(route('photo-posts.show', $post));
    expect(PhotoPost::count())->toBe(1);

    config(['photo_processing.decoder_executable' => null]);
    $sameRetry = photoPostUpload();
    $sameRetry['idempotency_key'] = $key;
    $this->post(route('photo-posts.store'), $sameRetry)->assertRedirect(route('photo-posts.show', $post));

    $conflict = photoPostUpload('A quiet morning ');
    $conflict['idempotency_key'] = $key;
    $this->post(route('photo-posts.store'), $conflict)->assertStatus(409);
    expect(PhotoPost::count())->toBe(1);
});

it('keeps the same retry key when cleanup is pending and reuses it after verified cleanup', function () {
    $owner = User::factory()->create();
    $key = (string) Str::uuid();
    $caption = 'A quiet morning';
    $payload = photoPostUpload($caption);
    $inputHash = hash_file('sha256', $payload['photo']->getRealPath());
    $fingerprint = hash('sha256', $inputHash."\0".$caption);
    $post = PhotoPost::factory()->for($owner)->create([
        'idempotency_key' => $key,
        'request_fingerprint' => $fingerprint,
        'status' => PhotoPostStatus::Failed,
        'staging_bytes_charged' => 12,
        'processed_bytes_charged' => 30,
        'cleanup_due_at' => now()->subSecond(),
        'cleanup_completed_at' => null,
    ]);
    $originalToken = $post->attempt_token;
    $payload['idempotency_key'] = $key;

    $this->actingAs($owner)->post(route('photo-posts.store'), $payload)
        ->assertSessionHasErrors(['photo' => 'Cleanup is still pending. Retry shortly with the same photo and caption.']);
    expect(PhotoPost::count())->toBe(1)
        ->and($post->fresh()->status)->toBe(PhotoPostStatus::Failed)
        ->and($post->fresh()->attempt_token)->toBe($originalToken)
        ->and($post->fresh()->idempotency_key)->toBe($key)
        ->and($post->fresh()->staging_bytes_charged)->toBe(12)
        ->and($post->fresh()->processed_bytes_charged)->toBe(30);

    expect(app(ManagePhotoPosts::class)->reconcile())->toBe(1);
    $retry = photoPostUpload($caption);
    $retry['idempotency_key'] = $key;
    $this->post(route('photo-posts.store'), $retry)->assertRedirect(route('photo-posts.show', $post));

    expect(PhotoPost::count())->toBe(1)
        ->and($post->fresh()->status)->toBe(PhotoPostStatus::Queued)
        ->and($post->fresh()->idempotency_key)->toBe($key)
        ->and($post->fresh()->attempt_token)->not->toBe($originalToken)
        ->and($post->fresh()->staging_bytes_charged)->toBeGreaterThan(0)
        ->and($post->fresh()->processed_bytes_charged)->toBe(0);
});

it('publishes only after bounded decoder output inspection and verified raw deletion', function () {
    $owner = User::factory()->create();
    $payload = photoPostUpload();
    $payload['idempotency_key'] = (string) Str::uuid();
    $this->actingAs($owner)->post(route('photo-posts.store'), $payload)->assertRedirect();
    $post = PhotoPost::sole();

    (new ProcessPhotoPost($post->id, $post->attempt_token))->handle(
        app(PhotoDecoder::class),
        app(WebpOutputInspector::class),
        app(ManagePhotoPosts::class),
    );

    $post->refresh();
    expect($post->status)->toBe(PhotoPostStatus::Ready)
        ->and($post->processed_bytes_charged)->toBe(30)
        ->and($post->staging_bytes_charged)->toBe(0)
        ->and($post->cleanup_completed_at)->not->toBeNull();
    Storage::disk('photo_posts')->assertMissing($post->raw_key);
    Storage::disk('photo_posts')->assertExists($post->output_key);
});

it('rejects malformed lossless WebP image chunks without publishing or retaining quota charges', function (string $output): void {
    setPhotoDecoderOutput($this->decoderDirectory, $output);
    $owner = User::factory()->create();
    $payload = photoPostUpload();
    $payload['idempotency_key'] = (string) Str::uuid();
    $this->actingAs($owner)->post(route('photo-posts.store'), $payload)->assertRedirect();
    $post = PhotoPost::sole();

    (new ProcessPhotoPost($post->id, $post->attempt_token))->handle(
        app(PhotoDecoder::class),
        app(WebpOutputInspector::class),
        app(ManagePhotoPosts::class),
    );

    expect($post->fresh()->status)->toBe(PhotoPostStatus::Failed)
        ->and($post->fresh()->processed_bytes_charged)->toBe(0)
        ->and($post->fresh()->staging_bytes_charged)->toBe(0)
        ->and($post->fresh()->cleanup_completed_at)->not->toBeNull();
    Storage::disk('photo_posts')->assertMissing($post->raw_key);
    Storage::disk('photo_posts')->assertMissing($post->output_key);
})->with([
    'duplicate lossless image bitstream' => [syntheticWebpWithChunks([
        ['VP8L', "\x2f\0\0\0\0"],
        ['VP8L', "\x2f\0\0\0\0"],
    ])],
    'lossless header shorter than five bytes before another chunk' => [syntheticWebpWithChunks([
        ['VP8L', "\x2f\0\0\0"],
        ['VP8L', "\x2f\0\0\0\0"],
    ])],
]);

it('keeps photo queue inserts on database.default when the general database queue uses another connection', function () {
    $queue = Queue::getFacadeRoot();
    Queue::swap($queue->queue);
    config([
        'database.connections.alternate' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'queue.connections.database.connection' => 'alternate',
    ]);
    expect(config('queue.connections.photo_processing.connection'))->toBeNull();

    $payload = photoPostUpload();
    $post = app(ManagePhotoPosts::class)->submit(
        User::factory()->create(),
        $payload['photo'],
        $payload['caption'],
        (string) Str::uuid(),
    );

    expect($post->status)->toBe(PhotoPostStatus::Queued)
        ->and(DB::table('jobs')->where('queue', 'photo-processing')->exists())->toBeTrue()
        ->and(DB::connection('alternate')->getSchemaBuilder()->hasTable('jobs'))->toBeFalse();
});

it('keeps cancellation pending until the queued worker confirms artifact cleanup', function () {
    $owner = User::factory()->create();
    $payload = photoPostUpload();
    $payload['idempotency_key'] = (string) Str::uuid();
    $this->actingAs($owner)->post(route('photo-posts.store'), $payload)->assertRedirect();
    $post = PhotoPost::sole();
    $rawKey = $post->raw_key;

    $this->post(route('photo-posts.cancel', $post))->assertRedirect(route('photo-posts.show', $post));
    expect($post->fresh()->status)->toBe(PhotoPostStatus::CancelRequested);
    Storage::disk('photo_posts')->assertExists($rawKey);

    (new ProcessPhotoPost($post->id, $post->attempt_token))->handle(
        app(PhotoDecoder::class),
        app(WebpOutputInspector::class),
        app(ManagePhotoPosts::class),
    );

    $post->refresh();
    expect($post->status)->toBe(PhotoPostStatus::Cancelled)
        ->and($post->staging_bytes_charged)->toBe(0)
        ->and($post->cleanup_completed_at)->not->toBeNull();
    Storage::disk('photo_posts')->assertMissing($rawKey);
});

it('does not release staging capacity while an upload writer still owns its cancellation cleanup', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Staging,
        'staging_bytes_charged' => 123,
    ]);
    Storage::disk('photo_posts')->put($post->raw_key, 'synthetic staged bytes');
    $posts = app(ManagePhotoPosts::class);

    $cancelled = $posts->cancel($owner, $post);
    expect($cancelled->status)->toBe(PhotoPostStatus::CancelRequested)
        ->and($cancelled->staging_bytes_charged)->toBe(123);
    Storage::disk('photo_posts')->assertExists($post->raw_key);

    $posts->finishFailure($post->id, $post->attempt_token, PhotoPostFailure::ProcessingFailed);
    $post->refresh();
    expect($post->status)->toBe(PhotoPostStatus::Cancelled)
        ->and($post->staging_bytes_charged)->toBe(0)
        ->and($post->cleanup_completed_at)->not->toBeNull();
    Storage::disk('photo_posts')->assertMissing($post->raw_key);
});

it('uses the same retained operation guard for stale staging cancellation and reconciliation', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Staging,
        'staging_bytes_charged' => 123,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);
    Storage::disk('photo_posts')->put($post->raw_key, 'synthetic staged bytes');
    $posts = app(ManagePhotoPosts::class);
    $guard = $posts->acquireOperationGuard($post->id);
    if ($guard === null) {
        throw new RuntimeException('Could not acquire the test operation guard.');
    }

    try {
        $cancelled = $posts->cancel($owner, $post);
        expect($cancelled->status)->toBe(PhotoPostStatus::CancelRequested)
            ->and($cancelled->staging_bytes_charged)->toBe(123)
            ->and($posts->reconcile())->toBe(0);
        Storage::disk('photo_posts')->assertExists($post->raw_key);
    } finally {
        $posts->releaseOperationGuard($guard);
    }

    expect($posts->reconcile())->toBe(1);
    $post->refresh();
    expect($post->status)->toBe(PhotoPostStatus::Cancelled)
        ->and($post->staging_bytes_charged)->toBe(0)
        ->and($post->cleanup_completed_at)->not->toBeNull();
    Storage::disk('photo_posts')->assertMissing($post->raw_key);
});

it('does not let a late failure cleanup delete a ready output under the operation guard', function () {
    $post = PhotoPost::factory()->create([
        'status' => PhotoPostStatus::Ready,
        'staging_bytes_charged' => 0,
        'processed_bytes_charged' => 30,
        'cleanup_completed_at' => now(),
    ]);
    Storage::disk('photo_posts')->put($post->output_key, 'synthetic ready WebP bytes');

    app(ManagePhotoPosts::class)->finishFailure(
        $post->id,
        $post->attempt_token,
        PhotoPostFailure::ProcessingFailed,
    );

    expect($post->fresh()->status)->toBe(PhotoPostStatus::Ready);
    Storage::disk('photo_posts')->assertExists($post->output_key);
});

it('fails closed when no fixed decoder launcher is configured', function () {
    config(['photo_processing.decoder_executable' => null]);
    expect(app(PhotoDecoder::class)->isAvailable())->toBeFalse();

    $payload = photoPostUpload();
    $payload['idempotency_key'] = (string) Str::uuid();
    $this->actingAs(User::factory()->create())->post(route('photo-posts.store'), $payload)
        ->assertSessionHasErrors(['photo' => 'Photo processing is temporarily unavailable. Try again later.']);
    expect(PhotoPost::count())->toBe(0);
});

it('rejects NUL in captions before admission while preserving ordinary newlines', function () {
    $owner = User::factory()->create();
    $invalid = photoPostUpload("before\0after");
    $invalid['idempotency_key'] = (string) Str::uuid();

    $this->actingAs($owner)->post(route('photo-posts.store'), $invalid)->assertSessionHasErrors(['caption']);
    expect(PhotoPost::count())->toBe(0)
        ->and(PhotoPost::sum('staging_bytes_charged'))->toBe(0)
        ->and(PhotoPost::sum('processed_bytes_charged'))->toBe(0)
        ->and(Storage::disk('photo_posts')->allFiles())->toBe([]);

    $caption = "line one\nline two";
    $valid = photoPostUpload($caption);
    $valid['idempotency_key'] = (string) Str::uuid();
    $this->post(route('photo-posts.store'), $valid)->assertRedirect();

    expect(PhotoPost::sole()->caption)->toBe($caption);
});

it('projects an owner retry key only when cleanup and decoder conditions are satisfied', function () {
    $owner = User::factory()->create();
    $post = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Failed,
        'staging_bytes_charged' => 0,
        'processed_bytes_charged' => 0,
        'cleanup_completed_at' => now(),
    ]);
    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version ?? ''];

    $this->actingAs($owner)->withHeaders($headers)->get(route('photo-posts.show', $post))
        ->assertOk()
        ->assertJsonPath('props.photoPost.canRetry', true)
        ->assertJsonPath('props.photoPost.retryKey', $post->idempotency_key)
        ->assertJsonMissingPath('props.decoderAvailable');

    $active = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Queued,
        'staging_bytes_charged' => 123,
    ]);
    $this->withHeaders($headers)->get(route('photo-posts.show', $post))
        ->assertOk()
        ->assertJsonPath('props.photoPost.canRetry', false)
        ->assertJsonPath('props.photoPost.retryKey', null);

    $active->delete();
    config(['photo_processing.decoder_executable' => null]);
    $this->withHeaders($headers)->get(route('photo-posts.show', $post))
        ->assertOk()
        ->assertJsonPath('props.photoPost.canRetry', false)
        ->assertJsonPath('props.photoPost.retryKey', null);
});

it('streams bounded stdin and stdout without exposing launcher diagnostics', function () {
    $input = $this->decoderDirectory.'/input';
    $output = $this->decoderDirectory.'/output';
    file_put_contents($input, 'synthetic input bytes');
    config(['photo_processing.max_output_bytes' => 10]);

    try {
        app(PhotoDecoder::class)->decode($input, $output);
        test()->fail('Expected output above the configured cap to be rejected.');
    } catch (PhotoDecoderException $exception) {
        expect($exception->getMessage())->toBe('processing_failed')
            ->and($exception->getMessage())->not->toContain('decoder detail');
    }

    expect(filesize($output))->toBeLessThanOrEqual(10);
});

it('accepts the exact decimal input cap and rejects the next byte', function () {
    $directory = $this->decoderDirectory;
    $input = $directory.'/exact-cap.jpg';
    $stream = fopen($input, 'wb');
    if (! is_resource($stream)) {
        throw new RuntimeException('Could not create the synthetic exact-cap input.');
    }
    ftruncate($stream, 20_000_000);
    fclose($stream);
    $owner = User::factory()->create();
    $posts = app(ManagePhotoPosts::class);

    $post = $posts->submit(
        $owner,
        new UploadedFile($input, 'synthetic.jpg', 'image/jpeg', null, true),
        null,
        (string) Str::uuid(),
    );
    expect($post->input_bytes)->toBe(20_000_000);

    $tooLarge = $directory.'/one-byte-over.jpg';
    $stream = fopen($tooLarge, 'wb');
    if (! is_resource($stream)) {
        throw new RuntimeException('Could not create the synthetic over-cap input.');
    }
    ftruncate($stream, 20_000_001);
    fclose($stream);

    try {
        $posts->submit(
            User::factory()->create(),
            new UploadedFile($tooLarge, 'synthetic.jpg', 'image/jpeg', null, true),
            null,
            (string) Str::uuid(),
        );
        test()->fail('Expected the byte above the decimal input cap to be rejected.');
    } catch (ValidationException) {
        expect(PhotoPost::count())->toBe(1);
    }
});

it('does not queue a post when enqueue fails inside the state transaction', function () {
    $queue = Queue::getFacadeRoot();
    Queue::swap($queue->queue);
    config(['queue.connections.photo_processing.table' => 'missing_photo_processing_jobs']);

    try {
        $payload = photoPostUpload();
        app(ManagePhotoPosts::class)->submit(
            User::factory()->create(),
            $payload['photo'],
            $payload['caption'],
            (string) Str::uuid(),
        );
        test()->fail('Expected the simulated queue failure to escape the request.');
    } catch (Throwable) {
        expect(PhotoPost::sole()->status)->toBe(PhotoPostStatus::Staging)
            ->and(PhotoPost::sole()->staging_bytes_charged)->toBeGreaterThan(0);
    }
});

it('fences a stale duplicate job after an attempt has been replaced', function () {
    $post = PhotoPost::factory()->create([
        'status' => PhotoPostStatus::Queued,
        'staging_bytes_charged' => 123,
    ]);
    $staleToken = (string) Str::uuid();
    Storage::disk('photo_posts')->put($post->raw_key, 'synthetic staged bytes');

    (new ProcessPhotoPost($post->id, $staleToken))->handle(
        app(PhotoDecoder::class),
        app(WebpOutputInspector::class),
        app(ManagePhotoPosts::class),
    );

    expect($post->fresh()->status)->toBe(PhotoPostStatus::Queued)
        ->and($post->fresh()->attempt_token)->toBe($post->attempt_token);
    Storage::disk('photo_posts')->assertExists($post->raw_key);
});

it('recovers expired processing only after acquiring its guard and preserves cancellation intent', function () {
    config(['photo_processing.processing_recovery_after_seconds' => 180]);
    $owner = User::factory()->create();
    $processing = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Processing,
        'processing_started_at' => now()->subSeconds(60),
        'staging_bytes_charged' => 123,
        'processed_bytes_charged' => 30,
    ]);
    $cancelled = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::CancelRequested,
        'processing_started_at' => now()->subSeconds(60),
        'staging_bytes_charged' => 123,
        'processed_bytes_charged' => 30,
    ]);
    Storage::disk('photo_posts')->put($processing->raw_key, 'synthetic raw');
    Storage::disk('photo_posts')->put($processing->output_key, 'synthetic partial output');
    Storage::disk('photo_posts')->put($cancelled->raw_key, 'synthetic raw');
    Storage::disk('photo_posts')->put($cancelled->output_key, 'synthetic partial output');
    $posts = app(ManagePhotoPosts::class);

    expect($posts->reconcile())->toBe(0);
    $guard = $posts->acquireOperationGuard($cancelled->id);
    if ($guard === null) {
        throw new RuntimeException('Could not acquire the test operation guard.');
    }
    try {
        $cancelled->update(['processing_started_at' => now()->subSeconds(181)]);
        expect($posts->reconcile())->toBe(0);
    } finally {
        $posts->releaseOperationGuard($guard);
    }

    $processing->update(['processing_started_at' => now()->subSeconds(181)]);
    expect($posts->reconcile())->toBe(2);
    expect($processing->fresh()->status)->toBe(PhotoPostStatus::Failed)
        ->and($cancelled->fresh()->status)->toBe(PhotoPostStatus::Cancelled)
        ->and($processing->fresh()->staging_bytes_charged)->toBe(0)
        ->and($processing->fresh()->processed_bytes_charged)->toBe(0)
        ->and($cancelled->fresh()->staging_bytes_charged)->toBe(0)
        ->and($cancelled->fresh()->processed_bytes_charged)->toBe(0);
    Storage::disk('photo_posts')->assertMissing($processing->raw_key);
    Storage::disk('photo_posts')->assertMissing($processing->output_key);
    Storage::disk('photo_posts')->assertMissing($cancelled->raw_key);
    Storage::disk('photo_posts')->assertMissing($cancelled->output_key);
});

it('retains staging and processed charges when artifact deletion fails, then releases them after verified cleanup', function () {
    $post = PhotoPost::factory()->create([
        'status' => PhotoPostStatus::Failed,
        'staging_bytes_charged' => 123,
        'processed_bytes_charged' => 30,
        'cleanup_due_at' => now()->subSecond(),
        'cleanup_completed_at' => null,
    ]);
    $root = Storage::disk('photo_posts')->path('');
    $objects = [$post->raw_key => true, $post->output_key => true];
    $deleteState = ['allowed' => false];
    $disk = Mockery::mock();
    $disk->shouldReceive('path')->andReturnUsing(fn (string $path): string => $root.'/'.$path);
    $disk->shouldReceive('exists')->andReturnUsing(function (string $key) use (&$objects): bool {
        return $objects[$key] ?? false;
    });
    $disk->shouldReceive('delete')->andReturnUsing(function (string $key) use (&$deleteState, &$objects): bool {
        if (! $deleteState['allowed']) {
            return false;
        }
        unset($objects[$key]);

        return true;
    });
    Storage::shouldReceive('disk')->with('photo_posts')->andReturn($disk);

    $posts = app(ManagePhotoPosts::class);
    expect($posts->reconcile())->toBe(0)
        ->and($post->fresh()->staging_bytes_charged)->toBe(123)
        ->and($post->fresh()->processed_bytes_charged)->toBe(30)
        ->and($post->fresh()->cleanup_completed_at)->toBeNull();

    $deleteState['allowed'] = true;
    PhotoPost::query()->whereKey($post->id)->update(['cleanup_due_at' => now()->subSecond()]);
    expect($post->fresh()->cleanup_due_at <= now())->toBeTrue();
    $guard = $posts->acquireOperationGuard($post->id);
    expect($guard)->not->toBeNull();
    $posts->releaseOperationGuard($guard);
    $reconciled = $posts->reconcile();
    expect($reconciled)->toBe(1)
        ->and($post->fresh()->staging_bytes_charged)->toBe(0)
        ->and($post->fresh()->processed_bytes_charged)->toBe(0)
        ->and($post->fresh()->cleanup_completed_at)->not->toBeNull();
});

it('counts retained processed cleanup debt against the exact output quota', function (int $quota, PhotoPostStatus $expected): void {
    config(['photo_processing.max_processed_bytes_per_user' => $quota]);
    $owner = User::factory()->create();
    PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Failed,
        'staging_bytes_charged' => 0,
        'processed_bytes_charged' => 30,
        'cleanup_due_at' => now()->addHour(),
        'cleanup_completed_at' => null,
    ]);
    $post = PhotoPost::factory()->for($owner)->create([
        'status' => PhotoPostStatus::Queued,
        'staging_bytes_charged' => 123,
    ]);
    Storage::disk('photo_posts')->put($post->raw_key, 'synthetic raw');

    (new ProcessPhotoPost($post->id, $post->attempt_token))->handle(
        app(PhotoDecoder::class),
        app(WebpOutputInspector::class),
        app(ManagePhotoPosts::class),
    );

    expect($post->fresh()->status)->toBe($expected);
})->with([
    'actual output exactly fills remaining quota' => [60, PhotoPostStatus::Ready],
    'actual output exceeds remaining quota by one byte' => [59, PhotoPostStatus::Failed],
]);
