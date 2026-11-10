<?php

use App\Support\PhotoCommentRequestBody;
use Symfony\Component\HttpFoundation\Request;

it('matches the comments route using Symfony path info, including script path aliases', function () {
    $alias = Request::create('/index.php/photo-posts/%31/comments/', 'POST', server: [
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => '/app/public/index.php',
        'PATH_INFO' => '/photo-posts/%31/comments/',
    ]);

    expect($alias->getPathInfo())->toBe('/photo-posts/%31/comments/')
        ->and(PhotoCommentRequestBody::appliesTo($alias))->toBeTrue()
        ->and(PhotoCommentRequestBody::appliesTo(Request::create('/photo-posts/abc/comments', 'POST')))->toBeFalse()
        ->and(PhotoCommentRequestBody::appliesTo(Request::create('/photo-posts/1/comments', 'GET')))->toBeFalse();
});

it('reads no more than the limit plus one byte and accepts exact-limit content', function () {
    $oversizedStream = fopen('php://temp', 'w+');
    fwrite($oversizedStream, str_repeat('x', PhotoCommentRequestBody::MAX_BYTES + 50_000));
    rewind($oversizedStream);

    expect(PhotoCommentRequestBody::read($oversizedStream))->toBeNull()
        ->and(ftell($oversizedStream))->toBe(PhotoCommentRequestBody::MAX_BYTES + 1);

    $validStream = fopen('php://temp', 'w+');
    $validBody = str_repeat('x', PhotoCommentRequestBody::MAX_BYTES);
    fwrite($validStream, $validBody);
    rewind($validStream);

    expect(PhotoCommentRequestBody::read($validStream))->toBe($validBody);
});
