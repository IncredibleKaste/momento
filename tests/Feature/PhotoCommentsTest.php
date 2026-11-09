<?php

use App\Enums\PhotoPostStatus;
use App\Models\Follow;
use App\Models\PhotoComment;
use App\Models\PhotoPost;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\EnforcingCsrfToken;

function commentPost(User $owner): PhotoPost
{
    return PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Ready]);
}

function commentPayload(string $body, ?string $key = null): array
{
    return ['body' => $body, 'submissionKey' => $key ?? (string) Str::uuid()];
}

it('returns the count, oldest-two preview and bounded oldest-first pages without private author fields', function () {
    $follow = Follow::factory()->approved()->create();
    $post = commentPost($follow->followed);
    $comments = collect(range(1, 32))->map(fn (int $number) => PhotoComment::query()->create([
        'photo_post_id' => $post->id,
        'author_id' => $follow->follower->id,
        'submission_key' => (string) Str::uuid(),
        'body_fingerprint' => hash('sha256', "comment {$number}"),
        'body' => "comment {$number}",
    ]));
    $comments[5]->forceFill(['body' => null, 'removed_at' => now()])->save();

    $this->actingAs($follow->follower)->getJson(route('photo-comments.index', $post))
        ->assertOk()->assertJsonPath('count', 31)->assertJsonPath('preview.0.body', 'comment 1')
        ->assertJsonPath('preview.1.body', 'comment 2')->assertJsonCount(30, 'comments')
        ->assertJsonPath('comments.0.body', 'comment 1')->assertJsonPath('comments.29.body', 'comment 31')
        ->assertJsonPath('comments.0.author.id', $follow->follower->id)
        ->assertJsonPath('comments.0.createdAt', $comments[0]->created_at?->toISOString())
        ->assertJsonPath('nextAfter', $comments[30]->id)
        ->assertJsonMissingPath('comments.0.author.email')->assertJsonMissingPath('comments.0.author.email_verified_at')
        ->assertJsonPath('comments.0.canRemove', true)
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs($follow->follower)->getJson(route('photo-comments.index', ['photoPost' => $post->id, 'after' => $comments[30]->id]))
        ->assertOk()->assertJsonPath('count', 31)->assertJsonCount(1, 'comments')
        ->assertJsonPath('comments.0.body', 'comment 32')->assertJsonPath('nextAfter', null);
});

it('requires ready-post photo visibility before validating or returning comments', function () {
    $owner = User::factory()->create();
    $pending = Follow::factory()->create();
    $post = commentPost($owner);
    $queued = PhotoPost::factory()->for($owner)->create(['status' => PhotoPostStatus::Queued]);

    $this->actingAs($pending->follower)->postJson(route('photo-comments.store', $post), ['body' => '', 'submissionKey' => 'bad'])
        ->assertNotFound()->assertJsonMissingPath('errors');
    $this->actingAs(User::factory()->create())->getJson(route('photo-comments.index', $post))->assertNotFound();
    auth()->logout();
    $this->getJson(route('photo-comments.index', $post))->assertUnauthorized();
    $this->actingAs($owner)->getJson(route('photo-comments.index', $queued))->assertNotFound();

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->getJson(route('photo-comments.index', $post))->assertForbidden();
});

it('stores bounded text under the authenticated author and safely returns exact matching replays', function () {
    $follow = Follow::factory()->approved()->create();
    $post = commentPost($follow->followed);
    $key = (string) Str::uuid();
    $body = str_repeat('😀', 500);

    $first = $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), [
        ...commentPayload($body, $key),
        'author_id' => $follow->followed->id,
    ])->assertCreated()->assertJsonPath('comment.body', $body)->assertJsonPath('comment.author.id', $follow->follower->id)
        ->assertJsonPath('replayed', false);
    $firstId = $first->json('comment.id');

    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload($body, $key))
        ->assertOk()->assertJsonPath('comment.id', $firstId)->assertJsonPath('replayed', true);
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload('different', $key))
        ->assertConflict();
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload(str_repeat('😀', 501)))
        ->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload('  '))
        ->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload("\u{00A0}\u{2003}"))
        ->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload("line one\nline two\r\nline three"))
        ->assertCreated()->assertJsonPath('comment.body', "line one\nline two\r\nline three");
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload("before\0after"))
        ->assertUnprocessable()->assertJsonValidationErrors('body');

    $preserved = "  \u{00A0}Keep exact\u{00A0}  ";
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload($preserved))
        ->assertCreated()->assertJsonPath('comment.body', $preserved);

    expect(PhotoComment::query()->where('photo_post_id', $post->id)->count())->toBe(3);
});

it('allows only the author or ready-post owner to remove, with a generic result and no retained body', function () {
    $follow = Follow::factory()->approved()->create();
    $post = commentPost($follow->followed);
    $key = (string) Str::uuid();
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload('Keep only until removal', $key))->assertCreated();
    $comment = PhotoComment::query()->firstOrFail();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->deleteJson(route('photo-comments.destroy', $comment))->assertNoContent();
    expect($comment->fresh()->body)->toBe('Keep only until removal');

    $this->actingAs($follow->followed)->deleteJson(route('photo-comments.destroy', $comment))->assertNoContent();
    expect($comment->fresh()->body)->toBeNull()->and($comment->fresh()->removed_at)->not->toBeNull()
        ->and($comment->fresh()->body_fingerprint)->toBe(str_repeat('0', 64));
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload('Keep only until removal', $key))->assertConflict();
    $this->actingAs($follow->followed)->getJson(route('photo-comments.index', $post))->assertJsonPath('count', 0);

    $secondKey = (string) Str::uuid();
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload('Author removes after access loss', $secondKey))->assertCreated();
    $secondComment = PhotoComment::query()->where('submission_key', $secondKey)->firstOrFail();
    Follow::query()->whereKey($follow->id)->delete();
    $this->actingAs($follow->follower)->deleteJson(route('photo-comments.destroy', $secondComment))->assertNoContent();
    expect($secondComment->fresh()->body)->toBeNull();
    $this->actingAs($follow->follower)->deleteJson('/photo-comments/999999')->assertNoContent();
});

it('limits valid submissions per actor and post', function () {
    $follow = Follow::factory()->approved()->create();
    $post = commentPost($follow->followed);
    RateLimiter::clear('photo-comments:actor:'.$follow->follower->id);
    RateLimiter::clear('photo-comments:post:'.$follow->follower->id.':'.$post->id);

    foreach (range(1, 12) as $number) {
        $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload("Comment {$number}"))->assertCreated();
    }
    $this->actingAs($follow->follower)->postJson(route('photo-comments.store', $post), commentPayload('Thirteenth comment'))
        ->assertTooManyRequests()->assertJsonPath('message', 'Too many comments. Wait a minute, then retry your draft.');
});

it('requires a valid session CSRF token for comment JSON mutations', function () {
    $follow = Follow::factory()->approved()->create();
    $post = commentPost($follow->followed);
    $token = Str::random(40);
    app()->bind(PreventRequestForgery::class, EnforcingCsrfToken::class);

    $index = $this->actingAs($follow->follower)->withSession(['_token' => $token])
        ->getJson(route('photo-comments.index', $post))->assertOk();
    $xsrfCookie = $index->getCookie('XSRF-TOKEN', decrypt: false);
    expect($xsrfCookie)->not->toBeNull();

    $this->actingAs($follow->follower)->withSession(['_token' => $token])->withHeader('X-XSRF-TOKEN', 'wrong-token')
        ->postJson(route('photo-comments.store', $post), commentPayload('csrf check'))
        ->assertStatus(419);

    $this->actingAs($follow->follower)->withSession(['_token' => $token])
        ->withHeader('X-XSRF-TOKEN', $xsrfCookie->getValue())
        ->postJson(route('photo-comments.store', $post), commentPayload('csrf check'))
        ->assertCreated();
});
