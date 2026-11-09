<?php

declare(strict_types=1);

const DECODER_EXECUTABLE = '/usr/local/bin/photo-decoder';
const SRGB_PROFILE_PATH = '/usr/local/share/momento-photo-decoder/sRGB2014.icc';
const POLICY_DIRECTORY = '/usr/local/share/momento-photo-decoder-policy';
const SRGB_PROFILE_SHA256 = '384b832de3412066743b52a75ee906b6fb9fb8d9e09e936fc2c43223815c6e0a';
const MAX_INPUT_BYTES = 20000000;
const MAX_OUTPUT_BYTES = 20000000;

final class PhotoProbeFailure extends RuntimeException {}

function requireProbe(bool $condition, string $stage): void
{
    if (! $condition) {
        throw new PhotoProbeFailure($stage);
    }
}

/**
 * @param  list<string>  $arguments
 * @return array{exit: int, stdout: string, stderr: string}
 */
function invokeDecoder(string $input, array $arguments = []): array
{
    $command = [DECODER_EXECUTABLE, ...$arguments];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);

    if (! is_resource($process)) {
        throw new RuntimeException('decoder-start');
    }

    $offset = 0;
    $inputLength = strlen($input);
    while ($offset < $inputLength) {
        $written = fwrite($pipes[0], substr($input, $offset, 65536));
        if (! is_int($written) || $written < 1) {
            break;
        }
        $offset += $written;
    }
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1], MAX_OUTPUT_BYTES + 1);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2], 512);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit' => $exit,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function makeImageBlob(string $format, int $width, int $height, bool $alpha = false, bool $oriented = false, bool $withProfile = false): string
{
    $image = new Imagick;
    try {
        if (! $image->newImage($width, $height, $alpha ? 'transparent' : '#0000ff')) {
            throw new RuntimeException('fixture-image');
        }

        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('#ff0000'));
        $draw->rectangle(0, 0, (int) floor($width / 2) - 1, $height - 1);
        $image->drawImage($draw);
        $draw->clear();
        $image->setImageFormat($format);
        $image->setImageCompressionQuality(95);

        if ($withProfile) {
            $profile = file_get_contents(SRGB_PROFILE_PATH);
            if (! is_string($profile) || ! $image->profileImage('icc', $profile)) {
                throw new RuntimeException('fixture-profile');
            }
        }

        if ($oriented) {
            $description = "synthetic-privacy-canary\0";
            $exif = "Exif\0\0II".pack('vVv', 42, 8, 2)
                .pack('vvVV', 0x010E, 2, strlen($description), 38)
                .pack('vvVV', 0x0112, 3, 1, 6)
                .pack('V', 0).$description;
            $image->setImageProfile('exif', $exif);
            $image->setImageProfile('xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
            $image->setImageProperty('comment', 'synthetic-privacy-canary');
            $image->setImageOrientation(Imagick::ORIENTATION_RIGHTTOP);
        }

        return $image->getImageBlob();
    } finally {
        $image->clear();
    }
}

function makeAnimatedWebp(): string
{
    $sequence = new Imagick;
    try {
        foreach (['#ff0000', '#0000ff'] as $color) {
            $frame = new Imagick;
            $frame->newImage(8, 8, $color);
            $frame->setImageFormat('WEBP');
            $frame->setImageDelay(10);
            $sequence->addImage($frame);
            $frame->clear();
        }
        $sequence->setImageFormat('WEBP');
        $sequence->setImageIterations(0);

        return $sequence->getImagesBlob();
    } finally {
        $sequence->clear();
    }
}

function overPixelJpeg(string $jpeg): string
{
    $length = strlen($jpeg);
    for ($offset = 2; $offset + 9 < $length;) {
        if (ord($jpeg[$offset]) !== 0xFF) {
            $offset++;

            continue;
        }

        while ($offset < $length && ord($jpeg[$offset]) === 0xFF) {
            $offset++;
        }
        if ($offset >= $length) {
            break;
        }

        $marker = ord($jpeg[$offset]);
        $offset++;
        if (in_array($marker, [0xD8, 0xD9, 0x01], true) || ($marker >= 0xD0 && $marker <= 0xD7)) {
            continue;
        }
        if ($offset + 2 > $length) {
            break;
        }

        $segmentLength = unpack('nlength', substr($jpeg, $offset, 2));
        if (! is_array($segmentLength) || $segmentLength['length'] < 2 || $offset + $segmentLength['length'] > $length) {
            break;
        }
        if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
            $heightOffset = $offset + 3;
            $widthOffset = $offset + 5;

            return substr_replace(
                substr_replace($jpeg, pack('n', 5000), $heightOffset, 2),
                pack('n', 10001),
                $widthOffset,
                2,
            );
        }

        $offset += $segmentLength['length'];
    }

    throw new RuntimeException('oversize-fixture');
}

function requireRejected(string $stage, string $input, int $expectedExit = 65): void
{
    $result = invokeDecoder($input);
    requireProbe($result['exit'] === $expectedExit, $stage.'-exit');
    requireProbe($result['stdout'] === '', $stage.'-stdout');
    requireProbe($result['stderr'] === "Invalid image.\n", $stage.'-stderr');
}

/** @return list<string> */
function webpChunkTypes(string $webp): array
{
    $length = strlen($webp);
    requireProbe($length >= 20 && substr($webp, 0, 4) === 'RIFF' && substr($webp, 8, 4) === 'WEBP', 'webp-header');
    $riffLength = unpack('Vlength', substr($webp, 4, 4));
    requireProbe(is_array($riffLength) && $riffLength['length'] === $length - 8, 'webp-riff-size');

    $types = [];
    for ($offset = 12; $offset < $length;) {
        requireProbe($offset + 8 <= $length, 'webp-chunk-header');
        $type = substr($webp, $offset, 4);
        $chunkLength = unpack('Vlength', substr($webp, $offset + 4, 4));
        requireProbe(is_array($chunkLength), 'webp-chunk-length');
        $next = $offset + 8 + $chunkLength['length'] + ($chunkLength['length'] % 2);
        requireProbe($next <= $length, 'webp-chunk-bounds');
        $types[] = $type;
        $offset = $next;
    }

    return $types;
}

/** @param array{exit: int, stdout: string, stderr: string} $result */
function inspectOutput(string $stage, array $result, int $width, int $height, bool $alpha, bool $orientationCanary): void
{
    if ($result['exit'] !== 0) {
        $failure = match ($result['stderr']) {
            "Invalid image.\n" => 'Invalid image.',
            "Image processing failed.\n" => 'Image processing failed.',
            default => 'unexpected',
        };
        throw new PhotoProbeFailure($stage.'-exit-'.$result['exit'].'-'.$failure);
    }
    requireProbe($result['stderr'] === '', $stage.'-stderr');
    requireProbe($result['stdout'] !== '' && strlen($result['stdout']) <= MAX_OUTPUT_BYTES, $stage.'-size');
    $chunks = webpChunkTypes($result['stdout']);
    requireProbe(! array_diff($chunks, ['VP8X', 'ALPH', 'VP8 ', 'VP8L']), $stage.'-chunks');
    requireProbe(! array_intersect($chunks, ['EXIF', 'XMP ', 'ICCP', 'ANIM', 'ANMF']), $stage.'-metadata');
    requireProbe(! str_contains($result['stdout'], 'synthetic-privacy-canary'), $stage.'-privacy-canary');

    $image = new Imagick;
    try {
        requireProbe($image->readImageBlob($result['stdout']), $stage.'-decode');
        requireProbe($image->getNumberImages() === 1, $stage.'-still');
        requireProbe($image->getImageFormat() === 'WEBP', $stage.'-format');
        requireProbe($image->getImageWidth() === $width && $image->getImageHeight() === $height, $stage.'-dimensions');
        requireProbe($image->getImageDepth() === 8, $stage.'-depth');
        requireProbe($image->getImageProfiles('*', false) === [], $stage.'-profiles');
        requireProbe(! $image->getImageProperty('comment'), $stage.'-comment');

        if ($alpha) {
            $transparent = $image->getImagePixelColor($width - 4, min($height - 1, 16))->getColor(1);
            requireProbe(($transparent['a'] ?? 1.0) < 0.02, $stage.'-alpha');
        }

        if ($orientationCanary) {
            $top = $image->getImagePixelColor(16, 8)->getColor(1);
            $bottom = $image->getImagePixelColor(16, 56)->getColor(1);
            requireProbe($top['r'] > 0.8 && $top['b'] < 0.2 && $bottom['b'] > 0.8 && $bottom['r'] < 0.2, $stage.'-orientation');
        }
    } finally {
        $image->clear();
    }
}

function verifyDedicatedPolicy(string $generatedImagePath): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1" fill="red"/></svg>';
    $script = 'error_reporting(0); ini_set("display_errors", "0"); '
        .'$svg = '.var_export($svg, true).'; $image = new Imagick; '
        .'try { $image->readImageBlob($svg); exit(10); } catch (ImagickException $exception) { '
        .'if (stripos($exception->getMessage(), "security policy") === false) { exit(11); } } '
        .'$image = new Imagick; try { $image->readImage('.var_export($generatedImagePath, true).'); exit(20); } '
        .'catch (ImagickException $exception) { if (stripos($exception->getMessage(), "security policy") === false) { exit(21); } } '
        .'$limits = ['
        .'Imagick::RESOURCETYPE_MEMORY => 268435456, Imagick::RESOURCETYPE_MAP => 536870912, '
        .'Imagick::RESOURCETYPE_DISK => 2147483648, Imagick::RESOURCETYPE_THREAD => 1, '
        .'Imagick::RESOURCETYPE_TIME => 120, Imagick::RESOURCETYPE_LISTLENGTH => 1, '
        .'Imagick::RESOURCETYPE_WIDTH => 50000, Imagick::RESOURCETYPE_HEIGHT => 50000]; '
        .'foreach ($limits as $resource => $limit) { if (Imagick::getResourceLimit($resource) > $limit) { exit(30); } '
        .'Imagick::setResourceLimit($resource, $limit + 1); if (Imagick::getResourceLimit($resource) > $limit) { exit(31); } } '
        .'echo "policy-ok";';

    $process = proc_open([PHP_BINARY, '-r', $script], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, ['MAGICK_CONFIGURE_PATH' => POLICY_DIRECTORY], ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('policy-child-start');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], 128);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2], 128);
    fclose($pipes[2]);
    $exit = proc_close($process);

    requireProbe($exit === 0 && $stdout === 'policy-ok' && $stderr === '', 'policy-effective');
}

$stage = 'setup';
$temporaryPath = sys_get_temp_dir().'/momento-photo-policy-'.bin2hex(random_bytes(10)).'.png';

try {
    requireProbe(extension_loaded('imagick'), 'imagick-required');
    requireProbe(hash_file('sha256', SRGB_PROFILE_PATH) === SRGB_PROFILE_SHA256, 'icc-asset');

    $stage = 'policy';
    $policyFixture = makeImageBlob('PNG', 4, 4);
    requireProbe(file_put_contents($temporaryPath, $policyFixture) === strlen($policyFixture), 'policy-fixture');
    verifyDedicatedPolicy($temporaryPath);
    requireProbe(unlink($temporaryPath), 'policy-fixture-cleanup');

    $stage = 'jpeg-fixture-generation';
    $jpeg = makeImageBlob('JPEG', 64, 32, false, true, true);
    $stage = 'jpeg-fixture-verification';
    $original = new Imagick;
    try {
        $original->readImageBlob($jpeg);
        requireProbe($original->getImageOrientation() === Imagick::ORIENTATION_RIGHTTOP, 'orientation-fixture');
        requireProbe($original->getImageProfiles('exif', false) !== [] && $original->getImageProfiles('xmp', false) !== [], 'metadata-fixture');
        requireProbe($original->getImageProfiles('icc', false) !== [], 'icc-fixture');
        requireProbe($original->getImageProperty('comment') === 'synthetic-privacy-canary', 'comment-fixture');
    } finally {
        $original->clear();
    }
    $stage = 'jpeg-orientation-icc';
    inspectOutput($stage, invokeDecoder($jpeg), 32, 64, false, true);

    $stage = 'png-alpha';
    inspectOutput($stage, invokeDecoder(makeImageBlob('PNG', 64, 32, true)), 64, 32, true, false);

    $stage = 'webp-alpha';
    inspectOutput($stage, invokeDecoder(makeImageBlob('WEBP', 64, 32, true)), 64, 32, true, false);

    $stage = 'heic-still';
    requireProbe(Imagick::queryFormats('HEIC') !== [], 'heic-codec');
    inspectOutput($stage, invokeDecoder(makeImageBlob('HEIC', 32, 16)), 32, 16, false, false);

    $stage = 'downscale-without-upscale';
    inspectOutput($stage, invokeDecoder(makeImageBlob('PNG', 5000, 10)), 4096, 8, false, false);
    inspectOutput('no-upscale', invokeDecoder(makeImageBlob('PNG', 32, 16)), 32, 16, false, false);

    $stage = 'animated-webp-rejected';
    $animated = makeAnimatedWebp();
    $sequence = new Imagick;
    try {
        $sequence->readImageBlob($animated);
        requireProbe($sequence->getNumberImages() === 2, 'animated-fixture');
    } finally {
        $sequence->clear();
    }
    requireRejected($stage, $animated);

    $stage = 'unsupported-gif-rejected';
    requireRejected($stage, makeImageBlob('GIF', 8, 8));

    $stage = 'malformed-input-rejected';
    requireRejected($stage, "not-an-image\0synthetic");

    $stage = 'input-byte-limit';
    requireRejected($stage, str_repeat('x', MAX_INPUT_BYTES + 1));

    $stage = 'pixel-limit';
    $oversize = overPixelJpeg(makeImageBlob('JPEG', 8, 8));
    $header = new Imagick;
    try {
        requireProbe($header->pingImageBlob($oversize), 'oversize-header-ping');
        requireProbe($header->getImageWidth() === 10001 && $header->getImageHeight() === 5000, 'oversize-header-dimensions');
    } finally {
        $header->clear();
    }
    requireRejected($stage, $oversize);

    $stage = 'no-arguments';
    $invocation = invokeDecoder('', ['unexpected']);
    requireProbe($invocation['exit'] === 64 && $invocation['stdout'] === ''
        && $invocation['stderr'] === "Invalid decoder invocation.\n", $stage);

    echo "Standalone photo decoder generated-input probe passed.\n";
} catch (Throwable $exception) {
    $failure = $exception instanceof PhotoProbeFailure ? $exception->getMessage() : $stage;
    fwrite(STDERR, 'Standalone photo decoder probe failed: '.$failure.".\n");
    exit(1);
} finally {
    if (is_file($temporaryPath) && ! is_link($temporaryPath)) {
        unlink($temporaryPath);
    }
}
