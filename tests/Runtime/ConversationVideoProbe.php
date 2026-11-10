<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

require '/app/vendor/autoload.php';

const FFMPEG_BIN = '/usr/bin/ffmpeg';
const FFPROBE_BIN = '/usr/bin/ffprobe';
const VIDEO_DECODER = '/usr/local/bin/conversation-video-decoder';

$temporaryDirectory = sys_get_temp_dir().'/momento-video-probe-'.bin2hex(random_bytes(8));
if (! mkdir($temporaryDirectory, 0700)) {
    throw new RuntimeException('Could not create the temporary probe directory.');
}
$temporaryDirectory = realpath($temporaryDirectory);
if (! is_string($temporaryDirectory)) {
    throw new RuntimeException('Could not resolve the temporary probe directory.');
}

try {
    ensure(is_executable(FFMPEG_BIN) && is_executable(FFPROBE_BIN) && is_executable(VIDEO_DECODER), 'Required native executable is missing.');
    assertCommand([FFMPEG_BIN, '-version']);
    assertCommand(['/usr/bin/timeout', '--version']);
    assertCommand(['/usr/bin/prlimit', '--version']);

    $ordinary = $temporaryDirectory.'/ordinary-end.mp4';
    createFixture($ordinary, 3, false, true, metadataCanary: true);
    ensure(moovIsAtEnd($ordinary), 'Ordinary fixture did not put moov at the end.');
    ensure(in_array('H.26[45] User Data Unregistered SEI message', frameSideDataTypes($ordinary), true), 'Ordinary H.264 fixture lacks its benign x264 encoder-info SEI.');
    decodeFixture($ordinary, $temporaryDirectory.'/ordinary-output.mp4');

    $fastStart = $temporaryDirectory.'/faststart.mp4';
    createFixture($fastStart, 3, true, false);
    ensure(! moovIsAtEnd($fastStart), 'Faststart fixture unexpectedly put moov at the end.');
    decodeFixture($fastStart, $temporaryDirectory.'/faststart-output.mp4');

    $explicitSdr = $temporaryDirectory.'/explicit-sdr.mp4';
    createFixture($explicitSdr, 3, true, false, explicitSdr: true);
    decodeFixture($explicitSdr, $temporaryDirectory.'/explicit-sdr-output.mp4');

    $square = $temporaryDirectory.'/square-input.mp4';
    createFixture($square, 2, true, false, width: 1600, height: 1600);
    $squareFacts = decodeFixture($square, $temporaryDirectory.'/square-output.mp4');
    ensure($squareFacts['width'] === 1440 && $squareFacts['height'] === 1440, 'Square output exceeded the Level 4.1 frame-size boundary.');

    $hdrSideData = $temporaryDirectory.'/hdr-side-data.mp4';
    createHdrSideDataFixture($hdrSideData);
    assertHdrSideDataRejected($hdrSideData, $temporaryDirectory.'/hdr-side-data-output.mp4');

    $rotated = $temporaryDirectory.'/rotated.mp4';
    assertCommand([
        FFMPEG_BIN, '-hide_banner', '-loglevel', 'error', '-y', '-i', $ordinary,
        '-map', '0', '-c', 'copy', '-metadata:s:v:0', 'rotate=90', $rotated,
    ]);
    $rotatedFacts = decodeFixture($rotated, $temporaryDirectory.'/rotated-output.mp4');
    ensure($rotatedFacts['width'] === 240 && $rotatedFacts['height'] === 320, 'Rotation metadata was not applied to pixels.');

    $exactLimit = $temporaryDirectory.'/exact-60.mp4';
    createFixture($exactLimit, 60, true, true, framesPerSecond: 1);
    decodeFixture($exactLimit, $temporaryDirectory.'/exact-60-output.mp4');

    $tooLong = $temporaryDirectory.'/too-long.mp4';
    createFixture($tooLong, 61, true, false, framesPerSecond: 1);
    assertRejected($tooLong, $temporaryDirectory.'/too-long-output.mp4');

    $forgedDuration = $temporaryDirectory.'/forged-duration.mp4';
    copy($tooLong, $forgedDuration);
    file_put_contents($forgedDuration, rewriteDurationHeaders(file_get_contents($forgedDuration), 60));
    assertRejected($forgedDuration, $temporaryDirectory.'/forged-duration-output.mp4');

    $malformed = $temporaryDirectory.'/malformed.mp4';
    file_put_contents($malformed, "not an MP4\0".str_repeat('x', 4096));
    assertRejected($malformed, $temporaryDirectory.'/malformed-output.mp4');

    $truncated = $temporaryDirectory.'/truncated.mp4';
    copy($ordinary, $truncated);
    $size = filesize($truncated);
    ensure(is_int($size) && $size > 128, 'Truncation fixture is too small.');
    file_put_contents($truncated, file_get_contents($truncated, false, null, 0, $size - 80));
    assertRejected($truncated, $temporaryDirectory.'/truncated-output.mp4');

    $brokenMedia = $temporaryDirectory.'/broken-media.mp4';
    createFixture($brokenMedia, 3, true, false);
    $brokenBytes = file_get_contents($brokenMedia);
    ensure(is_string($brokenBytes) && strlen($brokenBytes) > 256, 'Faststart corruption fixture is too small.');
    file_put_contents($brokenMedia, substr($brokenBytes, 0, -256));
    assertRejected($brokenMedia, $temporaryDirectory.'/broken-media-output.mp4');

    $extraStream = $temporaryDirectory.'/extra-stream.mp4';
    assertCommand([
        FFMPEG_BIN, '-hide_banner', '-loglevel', 'error', '-y',
        '-f', 'lavfi', '-i', 'color=c=blue:s=320x240:r=10:d=2',
        '-f', 'lavfi', '-i', 'color=c=red:s=320x240:r=10:d=2',
        '-map', '0:v', '-map', '1:v', '-c:v', 'libx264', '-threads', '1', $extraStream,
    ]);
    assertRejected($extraStream, $temporaryDirectory.'/extra-stream-output.mp4');

    $externalReference = $temporaryDirectory.'/external-reference.mp4';
    $canary = $temporaryDirectory.'/private-external-canary.bin';
    file_put_contents($canary, 'MOMENTO_EXTERNAL_DATA_REFERENCE_CANARY');
    $rewritten = rewriteExternalDataReference(file_get_contents($ordinary), $canary);
    file_put_contents($externalReference, $rewritten);
    assertRejected($externalReference, $temporaryDirectory.'/external-reference-output.mp4');
    ensure(! file_exists($temporaryDirectory.'/external-reference-output.mp4'), 'External dref failure left partial output behind.');

    echo "Synthetic MP4 decoder checks passed (ordinary seekable MP4, faststart, rotation, 60s cap, malformed/truncated, extra stream, external dref denial).\n";
} finally {
    foreach (glob($temporaryDirectory.'/*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }
    @rmdir($temporaryDirectory);
}

/** @param list<string> $command */
function assertCommand(array $command): string
{
    $process = new Process($command, timeout: 20);
    $process->run();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Synthetic fixture command failed.');
    }

    return $process->getOutput();
}

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function createFixture(
    string $path,
    int $duration,
    bool $fastStart,
    bool $audio,
    int $framesPerSecond = 10,
    bool $metadataCanary = false,
    int $width = 320,
    int $height = 240,
    bool $explicitSdr = false,
): void {
    $command = [
        FFMPEG_BIN, '-hide_banner', '-loglevel', 'error', '-y',
        '-f', 'lavfi', '-i', "color=c=blue:s={$width}x{$height}:r={$framesPerSecond}:d={$duration}",
    ];
    if ($audio) {
        $command = [...$command, '-f', 'lavfi', '-i', "sine=frequency=440:sample_rate=48000:duration={$duration}"];
    }
    $command = [...$command, '-map', '0:v:0'];
    if ($audio) {
        $command = [...$command, '-map', '1:a:0'];
    }
    $command = [...$command, '-c:v', 'libx264', '-threads', '1', '-pix_fmt', 'yuv420p'];
    if ($audio) {
        $command = [...$command, '-c:a', 'aac'];
    } else {
        $command = [...$command, '-an'];
    }
    if ($fastStart) {
        $command = [...$command, '-movflags', '+faststart'];
    }
    if ($metadataCanary) {
        $command = [...$command, '-metadata', 'comment=MOMENTO_INPUT_METADATA_CANARY'];
    }
    if ($explicitSdr) {
        $command = [...$command, '-color_primaries', 'bt709', '-color_trc', 'bt709', '-colorspace', 'bt709'];
    }
    $command[] = $path;
    assertCommand($command);
}

function createHdrSideDataFixture(string $path): void
{
    assertCommand([
        FFMPEG_BIN, '-hide_banner', '-loglevel', 'error', '-y',
        '-f', 'lavfi', '-i', 'color=c=blue:s=160x160:r=2:d=2',
        '-c:v', 'libx265', '-threads', '1', '-pix_fmt', 'yuv420p',
        '-x265-params', 'pools=1:frame-threads=1:repeat-headers=1:colorprim=bt709:transfer=bt709:colormatrix=bt709:master-display=G(13250,34500)B(7500,3000)R(34000,16000)WP(15635,16450)L(40000000,50):max-cll=1000,400',
        '-color_primaries', 'bt709', '-color_trc', 'bt709', '-colorspace', 'bt709', $path,
    ]);
}

function assertHdrSideDataRejected(string $input, string $output): void
{
    $probe = json_decode(assertCommand([
        FFPROBE_BIN, '-v', 'error', '-protocol_whitelist', 'file', '-f', 'mov',
        '-show_streams', '-show_format', '-show_chapters', '-of', 'json', $input,
    ]), true, flags: JSON_THROW_ON_ERROR);
    $transfer = $probe['streams'][0]['color_transfer'] ?? null;
    $sideDataTypes = array_column($probe['streams'][0]['side_data_list'] ?? [], 'side_data_type');
    ensure(in_array($transfer, ['unknown', 'bt709'], true), 'HDR canary fixture must carry SDR or missing color tags.');
    ensure(! array_intersect(['Mastering display metadata', 'Content light level metadata', 'DOVI configuration record'], $sideDataTypes), 'HDR side data unexpectedly appears at stream level.');
    $frameTypes = frameSideDataTypes($input);
    ensure(in_array('Mastering display metadata', $frameTypes, true) || in_array('Content light level metadata', $frameTypes, true), 'HDR fixture lacks frame-only mastering or content-light metadata.');
    assertRejected($input, $output);
}

/** @return list<string> */
function frameSideDataTypes(string $input): array
{
    $frameSideData = json_decode(assertCommand([
        FFPROBE_BIN, '-v', 'error', '-protocol_whitelist', 'file', '-f', 'mov',
        '-select_streams', 'v:0', '-show_frames', '-show_entries', 'frame_side_data=side_data_type', '-of', 'json', $input,
    ]), true, flags: JSON_THROW_ON_ERROR);
    $frameTypes = [];
    foreach ($frameSideData['frames'] ?? [] as $frame) {
        foreach ($frame['side_data_list'] ?? [] as $item) {
            if (is_string($item['side_data_type'] ?? null)) {
                $frameTypes[] = $item['side_data_type'];
            }
        }
    }

    return $frameTypes;
}

/** @return array{width: int, height: int} */
function decodeFixture(string $input, string $output): array
{
    $process = new Process([VIDEO_DECODER, $input, $output], timeout: 140);
    $process->run();
    if (! $process->isSuccessful() || ! is_file($output)) {
        throw new RuntimeException('The video decoder rejected a supported synthetic fixture.');
    }
    $facts = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($facts)
        || ! is_int($facts['bytes'] ?? null)
        || ! is_int($facts['width'] ?? null)
        || ! is_int($facts['height'] ?? null)
        || (! is_float($facts['durationSeconds'] ?? null) && ! is_int($facts['durationSeconds'] ?? null))
        || filesize($output) !== $facts['bytes']
        || $facts['bytes'] > 25_000_000
        || $facts['durationSeconds'] <= 0
        || $facts['durationSeconds'] > 60) {
        throw new RuntimeException('The video decoder returned invalid output facts.');
    }

    $probe = json_decode(assertCommand([
        FFPROBE_BIN, '-v', 'error', '-protocol_whitelist', 'file', '-f', 'mov',
        '-show_streams', '-show_format', '-show_chapters', '-of', 'json', $output,
    ]), true, flags: JSON_THROW_ON_ERROR);
    if (! in_array('mp4', explode(',', (string) ($probe['format']['format_name'] ?? '')), true)) {
        throw new RuntimeException('Decoder output is not a regular MP4 file.');
    }
    foreach ($probe['streams'] as $stream) {
        if (($stream['codec_type'] ?? null) === 'video'
            && (($stream['profile'] ?? null) !== 'High' || ($stream['level'] ?? null) !== 41 || ($stream['pix_fmt'] ?? null) !== 'yuv420p')) {
            throw new RuntimeException('Transcoded video is outside the accepted H.264 Level 4.1 profile.');
        }
        foreach (($stream['tags'] ?? []) as $value) {
            if (str_contains((string) $value, 'MOMENTO_')) {
                throw new RuntimeException('Input metadata canary survived transcoding.');
            }
        }
        if (($stream['codec_type'] ?? null) === 'video' && isset($stream['side_data_list'])) {
            foreach ($stream['side_data_list'] as $sideData) {
                if (isset($sideData['rotation']) && abs((float) $sideData['rotation']) > 0.01) {
                    throw new RuntimeException('Rotation metadata remains in output.');
                }
            }
        }
    }
    foreach (($probe['format']['tags'] ?? []) as $value) {
        if (str_contains((string) $value, 'MOMENTO_')) {
            throw new RuntimeException('Input container metadata canary survived transcoding.');
        }
    }
    if (($probe['chapters'] ?? []) !== []) {
        throw new RuntimeException('Input chapter metadata survived transcoding.');
    }

    return ['width' => $facts['width'], 'height' => $facts['height']];
}

function assertRejected(string $input, string $output): void
{
    $process = new Process([VIDEO_DECODER, $input, $output], timeout: 140);
    $process->run();
    if ($process->isSuccessful() || file_exists($output) || is_link($output)) {
        throw new RuntimeException('Invalid or over-limit input was accepted or left a partial output behind.');
    }
}

function moovIsAtEnd(string $path): bool
{
    $contents = file_get_contents($path);
    $offset = 0;
    $lastType = '';
    while (is_string($contents) && $offset + 8 <= strlen($contents)) {
        $size = unpack('Nsize', substr($contents, $offset, 4))['size'];
        $type = substr($contents, $offset + 4, 4);
        if ($size < 8 || $offset + $size > strlen($contents)) {
            return false;
        }
        $lastType = $type;
        $offset += $size;
    }

    return $offset === strlen((string) $contents) && $lastType === 'moov';
}

function rewriteExternalDataReference(string $contents, string $uri): string
{
    $found = false;
    $output = rewriteBoxes($contents, 0, $found, $uri);
    if (! $found) {
        throw new RuntimeException('Synthetic MP4 did not contain a self-contained data reference.');
    }

    return $output;
}

function rewriteDurationHeaders(string $contents, int $seconds): string
{
    $found = false;
    $rewritten = rewriteDurationBoxes($contents, 0, $found, $seconds);
    ensure($found, 'Could not locate MP4 duration headers for forged-duration fixture.');

    return $rewritten;
}

function rewriteDurationBoxes(string $bytes, int $start, bool &$found, int $seconds): string
{
    $containers = ['moov', 'trak', 'mdia', 'minf', 'dinf', 'edts', 'stbl'];
    $output = substr($bytes, 0, $start);
    $offset = $start;
    while ($offset + 8 <= strlen($bytes)) {
        $size = unpack('Nsize', substr($bytes, $offset, 4))['size'];
        $type = substr($bytes, $offset + 4, 4);
        if ($size < 8 || $offset + $size > strlen($bytes)) {
            throw new RuntimeException('Unexpected malformed MP4 box during duration fixture construction.');
        }
        $box = substr($bytes, $offset, $size);
        if (in_array($type, ['mvhd', 'mdhd'], true)) {
            $version = ord($box[8]);
            $timescaleOffset = $version === 1 ? 28 : 20;
            $durationOffset = $version === 1 ? 32 : 24;
            $timescale = unpack('Ntimescale', substr($box, $timescaleOffset, 4))['timescale'];
            if ($timescale < 1 || ($version !== 0 && $version !== 1)) {
                throw new RuntimeException('Unsupported MP4 duration header version.');
            }
            $duration = $seconds * $timescale;
            if ($version === 1) {
                $box = substr_replace($box, pack('N2', intdiv($duration, 4_294_967_296), $duration % 4_294_967_296), $durationOffset, 8);
            } else {
                $box = substr_replace($box, pack('N', $duration), $durationOffset, 4);
            }
            $found = true;
        } elseif (in_array($type, $containers, true)) {
            $rewritten = rewriteDurationBoxes($box, 8, $found, $seconds);
            $box = pack('N', strlen($rewritten) + 8).$type.substr($rewritten, 8);
        }
        $output .= $box;
        $offset += $size;
    }

    return $output.substr($bytes, $offset);
}

function rewriteBoxes(string $bytes, int $start, bool &$found, string $uri): string
{
    $containers = ['moov', 'trak', 'mdia', 'minf', 'dinf', 'edts', 'stbl'];
    $output = substr($bytes, 0, $start);
    $offset = $start;
    while ($offset + 8 <= strlen($bytes)) {
        $size = unpack('Nsize', substr($bytes, $offset, 4))['size'];
        $type = substr($bytes, $offset + 4, 4);
        if ($size < 8 || $offset + $size > strlen($bytes)) {
            throw new RuntimeException('Unexpected malformed MP4 box during dref fixture construction.');
        }
        $box = substr($bytes, $offset, $size);
        if ($type === 'dref') {
            $header = substr($box, 8, 8);
            $entry = substr($box, 16);
            $entryType = substr($entry, 4, 4);
            $entrySize = unpack('Nsize', substr($entry, 0, 4))['size'] ?? 0;
            if ($entryType !== 'url ' || $entrySize !== 12 || substr($entry, 8, 4) !== "\0\0\0\1") {
                throw new RuntimeException('Synthetic dref was not a self-contained URL entry.');
            }
            $url = pack('N', 12 + strlen($uri) + 1).'url '."\0\0\0\0".$uri."\0";
            $box = pack('N', 16 + strlen($url)).'dref'.substr($box, 8, 8).$url;
            $found = true;
        } elseif (in_array($type, $containers, true)) {
            $rewritten = rewriteBoxes($box, 8, $found, $uri);
            $box = pack('N', strlen($rewritten) + 8).$type.substr($rewritten, 8);
        }
        $output .= $box;
        $offset += $size;
    }

    return $output.substr($bytes, $offset);
}
