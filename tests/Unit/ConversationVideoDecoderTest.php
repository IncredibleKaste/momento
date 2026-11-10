<?php

use App\Exceptions\ConversationVideoException;
use App\Services\ConversationVideoDecoder;
use Tests\TestCase;

uses(TestCase::class);

function makeConversationVideoDecoderFixture(string $script): array
{
    $directory = sys_get_temp_dir().'/momento-video-unit-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $directory = realpath($directory);
    if (! is_string($directory)) {
        throw new RuntimeException('Could not resolve the synthetic fixture directory.');
    }
    $executable = $directory.'/decoder';
    file_put_contents($executable, "#!/bin/sh\nset -eu\n".$script."\n");
    chmod($executable, 0700);
    $input = $directory.'/input.mp4';
    file_put_contents($input, 'synthetic input');

    config(['conversation_attachments.video_decoder_executable' => $executable]);

    return [$directory, $executable, $input];
}

function removeConversationVideoDecoderFixture(string $directory): void
{
    foreach (glob($directory.'/*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}

it('returns inspected output facts from the configured isolated decoder', function () {
    [$directory, , $input] = makeConversationVideoDecoderFixture(
        'printf MP4DATA > "$2"; printf \'{"bytes":7,"width":640,"height":480,"durationSeconds":2.5}\' ',
    );

    try {
        expect(app(ConversationVideoDecoder::class)->decode($input, $directory.'/output.mp4'))
            ->toBe(['bytes' => 7, 'width' => 640, 'height' => 480, 'durationSeconds' => 2.5]);
    } finally {
        removeConversationVideoDecoderFixture($directory);
    }
});

it('does not launch the decoder for a symlink input or an existing output', function () {
    $marker = sys_get_temp_dir().'/momento-video-invoked-'.bin2hex(random_bytes(8));
    [$directory, $executable, $input] = makeConversationVideoDecoderFixture(
        'touch '.escapeshellarg($marker).'; exit 70',
    );
    $output = $directory.'/existing.mp4';
    file_put_contents($output, 'keep');
    $symlink = $directory.'/input-link.mp4';
    symlink($input, $symlink);
    $decoder = app(ConversationVideoDecoder::class);

    try {
        expect(fn () => $decoder->decode($symlink, $directory.'/new.mp4'))
            ->toThrow(ConversationVideoException::class);
        expect(fn () => $decoder->decode($input, $output))
            ->toThrow(ConversationVideoException::class);
        expect(file_get_contents($output))->toBe('keep');
        expect(file_exists($marker))->toBeFalse();
        expect(is_executable($executable))->toBeTrue();
    } finally {
        removeConversationVideoDecoderFixture($directory);
        if (file_exists($marker)) {
            unlink($marker);
        }
    }
});

it('rejects a decoder result that exceeds the configured output cap', function () {
    [$directory, , $input] = makeConversationVideoDecoderFixture(
        'truncate -s 25000001 "$2"; printf \'{"bytes":25000001,"width":640,"height":480,"durationSeconds":2}\' ',
    );

    try {
        expect(fn () => app(ConversationVideoDecoder::class)->decode($input, $directory.'/output.mp4'))
            ->toThrow(ConversationVideoException::class);
    } finally {
        removeConversationVideoDecoderFixture($directory);
    }
});

it('rejects decoded dimensions beyond the H.264 Level 4.1 output bound', function () {
    [$directory, , $input] = makeConversationVideoDecoderFixture(
        'printf MP4DATA > "$2"; printf \'{"bytes":7,"width":1442,"height":1080,"durationSeconds":2.0}\' ',
    );

    try {
        expect(fn () => app(ConversationVideoDecoder::class)->decode($input, $directory.'/output.mp4'))
            ->toThrow(ConversationVideoException::class);
    } finally {
        removeConversationVideoDecoderFixture($directory);
    }
});
