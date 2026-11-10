<?php

declare(strict_types=1);

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

require '/app/vendor/autoload.php';

const FFPROBE = '/usr/bin/ffprobe';
const FFMPEG = '/usr/bin/ffmpeg';
const TIMEOUT = '/usr/bin/timeout';
const PRLIMIT = '/usr/bin/prlimit';
const MAX_INPUT_BYTES = 25_000_000;
const MAX_OUTPUT_BYTES = 25_000_000;
const MAX_DURATION_SECONDS = 60.0;
const MAX_PROBE_OUTPUT_BYTES = 65_536;
const MAX_FRAME_SCAN_OUTPUT_BYTES = 1_048_576;
const MAX_NATIVE_STDERR_BYTES = 4_096;
const MAX_LONG_EDGE = 1_440;
const MAX_INPUT_LONG_EDGE = 4_096;
const MAX_INPUT_PIXELS = 12_000_000;
const MEMORY_LIMIT_BYTES = 1_073_741_824;

error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

final class VideoProcessingFailure extends RuntimeException {}

function failVideo(): never
{
    throw new VideoProcessingFailure;
}

/** @param array{cause: string, exit: int|null, signal: int|null, stderr: string}|null $detail */
function reportFailure(string $phase, ?array $detail = null): never
{
    $phase = match ($phase) {
        'input_probe', 'input_validation', 'frame_scan', 'transcode', 'output_probe', 'output_validation' => $phase,
        default => 'input_validation',
    };

    fwrite(STDERR, 'F28_PHASE='.$phase."\n");
    if ($phase === 'transcode' && $detail !== null) {
        $cause = in_array($detail['cause'], ['wrapper_exit', 'wrapper_signal', 'tool_unavailable', 'process_timeout', 'process_error', 'stdout_limit', 'output_missing', 'output_empty', 'output_too_large', 'output_path_invalid'], true)
            ? $detail['cause']
            : 'process_error';
        $stderr = in_array($detail['stderr'], ['resource', 'filter_option', 'encoder', 'io', 'other', 'none'], true)
            ? $detail['stderr']
            : 'other';
        $exit = is_int($detail['exit']) && $detail['exit'] >= 0 && $detail['exit'] <= 255 ? (string) $detail['exit'] : 'none';
        $signal = is_int($detail['signal']) && $detail['signal'] >= 1 && $detail['signal'] <= 64 ? (string) $detail['signal'] : 'none';
        fwrite(STDERR, 'F28_CAUSE='.$cause."\n");
        fwrite(STDERR, 'F28_EXIT='.$exit."\n");
        fwrite(STDERR, 'F28_SIGNAL='.$signal."\n");
        fwrite(STDERR, 'F28_STDERR='.$stderr."\n");
    }
    exit(70);
}

function validInput(string $path): bool
{
    if (! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path) || ! is_file($path)) {
        return false;
    }

    $resolved = realpath($path);
    $bytes = @filesize($path);

    return $resolved === $path && is_int($bytes) && $bytes > 0 && $bytes <= MAX_INPUT_BYTES;
}

function validOutput(string $path): bool
{
    $directory = dirname($path);

    return str_starts_with($path, DIRECTORY_SEPARATOR)
        && realpath($directory) === $directory
        && is_dir($directory)
        && ! file_exists($path)
        && ! is_link($path)
        && is_writable($directory);
}

/** @return array<string, mixed>|null */
function probe(string $path, int $wallSeconds): ?array
{
    $json = runNative([
        FFPROBE,
        '-v', 'error',
        '-protocol_whitelist', 'file',
        '-f', 'mov',
        '-enable_drefs', '0',
        '-use_absolute_path', '0',
        '-show_streams',
        '-show_format',
        '-show_chapters',
        '-of', 'json',
        $path,
    ], $wallSeconds, min($wallSeconds, 8), MAX_PROBE_OUTPUT_BYTES, MAX_INPUT_BYTES);

    if ($json === null) {
        return null;
    }

    try {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($value) ? $value : null;
}

function framesHaveUnexpectedSideData(string $path): bool
{
    $json = runNative([
        FFPROBE,
        '-v', 'error',
        '-protocol_whitelist', 'file',
        '-f', 'mov',
        '-enable_drefs', '0',
        '-use_absolute_path', '0',
        '-select_streams', 'v:0',
        '-show_frames',
        '-show_entries', 'frame_side_data=side_data_type',
        '-of', 'json',
        $path,
    ], 25, 20, MAX_FRAME_SCAN_OUTPUT_BYTES, MAX_INPUT_BYTES);

    if ($json === null) {
        return true;
    }

    try {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return true;
    }

    $frames = is_array($value) ? ($value['frames'] ?? null) : null;
    if (! is_array($frames) || $frames === []) {
        return true;
    }

    foreach ($frames as $frame) {
        if (! is_array($frame)) {
            return true;
        }
        $sideData = $frame['side_data_list'] ?? [];
        if (! is_array($sideData)) {
            return true;
        }
        foreach ($sideData as $item) {
            if (! is_array($item) || ! is_string($item['side_data_type'] ?? null)) {
                return true;
            }
            if (! in_array($item['side_data_type'], [
                'H.26[45] User Data Unregistered SEI message',
                'Display Matrix',
                '3x3 displaymatrix',
            ], true)) {
                return true;
            }
        }
    }

    return false;
}

/** @param array{cause: string, exit: int|null, signal: int|null, stderr: string}|null $failure */
function runNative(array $arguments, int $wallSeconds, int $cpuSeconds, int $stdoutLimit, int $fileLimit, ?array &$failure = null): ?string
{
    if (! is_executable(TIMEOUT) || ! is_executable(PRLIMIT)) {
        $failure = ['cause' => 'tool_unavailable', 'exit' => null, 'signal' => null, 'stderr' => 'none'];

        return null;
    }

    $command = [
        TIMEOUT,
        '--signal=TERM',
        '--kill-after=1s',
        $wallSeconds.'s',
        PRLIMIT,
        '--fsize='.$fileLimit.':'.$fileLimit,
        '--as='.MEMORY_LIMIT_BYTES.':'.MEMORY_LIMIT_BYTES,
        '--cpu='.$cpuSeconds.':'.($cpuSeconds + 5),
        '--',
        ...$arguments,
    ];
    $process = new Process($command, timeout: $wallSeconds + 4);
    $process->disableOutput();
    $stdout = '';
    $overflow = false;
    $stderr = '';

    try {
        $process->start(function (string $type, string $data) use ($process, $stdoutLimit, &$stdout, &$overflow, &$stderr): void {
            if ($type === Process::ERR) {
                if (strlen($stderr) < MAX_NATIVE_STDERR_BYTES) {
                    $stderr .= substr($data, 0, MAX_NATIVE_STDERR_BYTES - strlen($stderr));
                }

                return;
            }
            if ($type !== Process::OUT) {
                return;
            }

            if (strlen($stdout) + strlen($data) > $stdoutLimit) {
                $overflow = true;
                $process->stop(0);

                return;
            }

            $stdout .= $data;
        });
        $process->wait();
    } catch (ProcessTimedOutException) {
        $failure = ['cause' => 'process_timeout', 'exit' => null, 'signal' => null, 'stderr' => classifyNativeStderr($stderr)];

        return null;
    } catch (Throwable) {
        $failure = ['cause' => 'process_error', 'exit' => null, 'signal' => null, 'stderr' => classifyNativeStderr($stderr)];

        return null;
    }

    $exit = $process->getExitCode();
    $signal = null;
    try {
        if ($process->hasBeenSignaled()) {
            $signal = $process->getTermSignal();
        }
    } catch (Throwable) {
        $signal = null;
    }

    if ($overflow) {
        $failure = ['cause' => 'stdout_limit', 'exit' => $exit, 'signal' => $signal, 'stderr' => 'none'];

        return null;
    }
    if ($exit !== 0) {
        $cause = $signal !== null ? 'wrapper_signal' : 'wrapper_exit';
        $failure = ['cause' => $cause, 'exit' => $exit, 'signal' => $signal, 'stderr' => classifyNativeStderr($stderr)];

        return null;
    }

    return $stdout;
}

function classifyNativeStderr(string $stderr): string
{
    $message = strtolower($stderr);
    if ($message === '') {
        return 'none';
    }
    if (preg_match('/cannot allocate memory|out of memory|failed to create thread|pthread_create|resource temporarily unavailable/', $message) === 1) {
        return 'resource';
    }
    if (preg_match('/unrecognized option|error applying option|error parsing filter|no such filter/', $message) === 1) {
        return 'filter_option';
    }
    if (preg_match('/unknown encoder|encoder .* not found|no encoder found/', $message) === 1) {
        return 'encoder';
    }
    if (preg_match('/permission denied|read-only file system|no such file or directory|error opening input|error opening output/', $message) === 1) {
        return 'io';
    }

    return 'other';
}

/** @param array<string, mixed> $probe */
function validInputProbe(array $probe): ?array
{
    $format = $probe['format'] ?? null;
    $streams = $probe['streams'] ?? null;
    if (! is_array($format) || ! is_array($streams)) {
        return null;
    }

    $formatNames = explode(',', (string) ($format['format_name'] ?? ''));
    $tags = $format['tags'] ?? [];
    $brand = is_array($tags) ? ($tags['major_brand'] ?? '') : '';
    if (! in_array('mp4', $formatNames, true) || ! is_string($brand) || ! acceptedBrand($brand)) {
        return null;
    }

    $duration = numericDuration($format['duration'] ?? null);
    if ($duration === null || $duration > MAX_DURATION_SECONDS) {
        return null;
    }

    $videos = [];
    $audios = [];
    foreach ($streams as $stream) {
        if (! is_array($stream)) {
            return null;
        }
        if (! allowedInputSideData($stream)) {
            return null;
        }
        if (($stream['codec_type'] ?? null) === 'video') {
            $videos[] = $stream;
        } elseif (($stream['codec_type'] ?? null) === 'audio') {
            $audios[] = $stream;
        } else {
            return null;
        }
    }

    if (count($videos) !== 1 || count($audios) > 1) {
        return null;
    }

    $video = $videos[0];
    $width = filter_var($video['width'] ?? null, FILTER_VALIDATE_INT);
    $height = filter_var($video['height'] ?? null, FILTER_VALIDATE_INT);
    $videoDuration = numericDuration($video['duration'] ?? null);
    $frameRate = frameRate($video['avg_frame_rate'] ?? null);
    if (! in_array($video['codec_name'] ?? null, ['h264', 'hevc'], true)
        || ! is_int($width)
        || ! is_int($height)
        || $width < 1
        || $height < 1
        || max($width, $height) > MAX_INPUT_LONG_EDGE
        || $width * $height > MAX_INPUT_PIXELS
        || ! in_array($video['pix_fmt'] ?? null, ['yuv420p', 'yuvj420p', 'yuv420p10le'], true)
        || ($videoDuration !== null && $videoDuration > MAX_DURATION_SECONDS)
        || $frameRate === null
        || $frameRate > 120
        || ! allowedColor($video)) {
        return null;
    }

    if ($audios !== [] && ! in_array($audios[0]['codec_name'] ?? null, ['aac', 'mp3'], true)) {
        return null;
    }

    return ['width' => $width, 'height' => $height];
}

function acceptedBrand(string $brand): bool
{
    return in_array(trim($brand), ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V', 'dash'], true);
}

function numericDuration(mixed $value): ?float
{
    if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
        return null;
    }

    $duration = (float) $value;

    return is_finite($duration) && $duration > 0 ? $duration : null;
}

function frameRate(mixed $value): ?float
{
    if (! is_string($value) || preg_match('/\A([0-9]+)\/([0-9]+)\z/D', $value, $matches) !== 1) {
        return null;
    }

    $numerator = (float) $matches[1];
    $denominator = (float) $matches[2];
    if ($denominator <= 0) {
        return null;
    }

    $rate = $numerator / $denominator;

    return is_finite($rate) && $rate > 0 ? $rate : null;
}

/** @param array<string, mixed> $video */
function allowedColor(array $video): bool
{
    $allowed = [
        'color_transfer' => ['unknown', 'bt709', 'smpte170m', 'bt470bg', 'gamma22', 'gamma28', 'smpte240m'],
        'color_primaries' => ['unknown', 'bt709', 'smpte170m', 'bt470bg'],
        'color_space' => ['unknown', 'bt709', 'smpte170m', 'bt470bg', 'fcc', 'smpte240m'],
    ];

    foreach ($allowed as $key => $values) {
        $value = $video[$key] ?? 'unknown';
        if (! is_string($value) || ! in_array($value, $values, true)) {
            return false;
        }
    }

    return true;
}

/** @param array<string, mixed> $stream */
function allowedInputSideData(array $stream): bool
{
    $sideData = $stream['side_data_list'] ?? [];
    if (! is_array($sideData)) {
        return false;
    }

    foreach ($sideData as $item) {
        if (! is_array($item) || ! is_string($item['side_data_type'] ?? null)) {
            return false;
        }

        $allowed = match ($item['side_data_type']) {
            'CPB properties' => true,
            'Display Matrix' => ($stream['codec_type'] ?? null) === 'video',
            default => false,
        };
        if (! $allowed) {
            return false;
        }
    }

    return true;
}

/** @param array<string, mixed> $probe */
function validOutputProbe(array $probe): ?array
{
    $format = $probe['format'] ?? null;
    $streams = $probe['streams'] ?? null;
    $chapters = $probe['chapters'] ?? [];
    if (! is_array($format) || ! is_array($streams) || ! is_array($chapters) || $chapters !== []) {
        return null;
    }

    if (! in_array('mp4', explode(',', (string) ($format['format_name'] ?? '')), true)) {
        return null;
    }
    $duration = numericDuration($format['duration'] ?? null);
    if ($duration === null || $duration > MAX_DURATION_SECONDS || count($streams) < 1 || count($streams) > 2) {
        return null;
    }

    $videos = [];
    $audios = [];
    foreach ($streams as $stream) {
        if (! is_array($stream)) {
            return null;
        }
        if (($stream['codec_type'] ?? null) === 'video') {
            $videos[] = $stream;
        } elseif (($stream['codec_type'] ?? null) === 'audio') {
            $audios[] = $stream;
        } else {
            return null;
        }

        if (! validOutputStreamTags($stream['tags'] ?? [], ($stream['codec_type'] ?? null) === 'video')) {
            return null;
        }
    }

    if (count($videos) !== 1 || count($audios) > 1 || ! validOutputFormatTags($format['tags'] ?? [])) {
        return null;
    }

    $video = $videos[0];
    $width = filter_var($video['width'] ?? null, FILTER_VALIDATE_INT);
    $height = filter_var($video['height'] ?? null, FILTER_VALIDATE_INT);
    $rate = frameRate($video['avg_frame_rate'] ?? null);
    if (($video['codec_name'] ?? null) !== 'h264'
        || ! is_int($width)
        || ! is_int($height)
        || $width < 1
        || $height < 1
        || max($width, $height) > MAX_LONG_EDGE
        || ($width % 2) !== 0
        || ($height % 2) !== 0
        || $rate === null
        || $rate > 30.001
        || ($video['profile'] ?? null) !== 'High'
        || ($video['level'] ?? null) !== 41
        || ($video['pix_fmt'] ?? null) !== 'yuv420p'
        || hasRotation($video)) {
        return null;
    }

    if ($audios !== [] && ($audios[0]['codec_name'] ?? null) !== 'aac') {
        return null;
    }

    return ['width' => $width, 'height' => $height, 'duration' => $duration];
}

/** @param array<string, mixed> $stream */
function hasRotation(array $stream): bool
{
    foreach (($stream['side_data_list'] ?? []) as $sideData) {
        if (is_array($sideData) && isset($sideData['rotation']) && abs((float) $sideData['rotation']) > 0.01) {
            return true;
        }
    }

    return false;
}

function validOutputFormatTags(mixed $tags): bool
{
    if (! is_array($tags)) {
        return false;
    }

    foreach ($tags as $key => $value) {
        if (! is_string($value)) {
            return false;
        }

        $valid = match ($key) {
            'major_brand' => acceptedBrand($value),
            'minor_version' => preg_match('/\A[0-9]{1,8}\z/D', $value) === 1,
            'compatible_brands' => preg_match('/\A[A-Za-z0-9 ]{1,128}\z/D', $value) === 1,
            'encoder' => preg_match('/\ALavf[0-9.]{1,24}\z/D', $value) === 1,
            default => false,
        };
        if (! $valid) {
            return false;
        }
    }

    return true;
}

function validOutputStreamTags(mixed $tags, bool $video): bool
{
    if (! is_array($tags)) {
        return false;
    }

    foreach ($tags as $key => $value) {
        if (! is_string($value)) {
            return false;
        }

        $valid = match ($key) {
            'language' => $value === 'und',
            'handler_name' => $value === ($video ? 'VideoHandler' : 'SoundHandler'),
            'vendor_id' => in_array($value, ['[0][0][0][0]', 'FFMP'], true),
            'encoder' => preg_match('/\ALavc[0-9.]{1,24} '.($video ? 'libx264' : 'aac').'\z/D', $value) === 1,
            default => false,
        };
        if (! $valid) {
            return false;
        }
    }

    return true;
}

function removePartialOutput(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
    }
}

if ($argc !== 3 || ! validInput($argv[1]) || ! validOutput($argv[2])) {
    reportFailure('input_validation');
}

$inputPath = $argv[1];
$outputPath = $argv[2];
$success = false;
$failurePhase = 'input_validation';

try {
    $failurePhase = 'input_probe';
    $inputProbe = probe($inputPath, 5);
    if ($inputProbe === null) {
        failVideo();
    }
    $failurePhase = 'input_validation';
    $dimensions = validInputProbe($inputProbe);
    if ($dimensions === null) {
        failVideo();
    }
    $failurePhase = 'frame_scan';
    if (framesHaveUnexpectedSideData($inputPath)) {
        failVideo();
    }

    $filter = "scale=w='min(1440,iw)':h='min(1440,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1,fps='min(30,source_fps)',format=yuv420p";
    $failurePhase = 'transcode';
    $transcodeFailure = null;
    $transcode = runNative([
        FFMPEG,
        '-nostdin',
        '-hide_banner',
        '-loglevel', 'error',
        '-filter_threads', '2',
        '-xerror',
        '-n',
        '-protocol_whitelist', 'file',
        '-f', 'mov',
        '-enable_drefs', '0',
        '-use_absolute_path', '0',
        '-autorotate',
        '-threads:v', '2',
        '-i', $inputPath,
        '-map', '0:v:0',
        '-map', '0:a:0?',
        '-sn',
        '-dn',
        '-map_metadata', '-1',
        '-map_metadata:s', '-1',
        '-map_chapters', '-1',
        '-vf', $filter,
        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-crf', '25',
        '-maxrate', '2600k',
        '-bufsize', '5200k',
        '-profile:v', 'high',
        '-level:v', '4.1',
        '-threads:v', '2',
        '-c:a', 'aac',
        '-b:a', '128k',
        '-ac', '2',
        '-ar', '48000',
        '-movflags', '+faststart',
        '-f', 'mp4',
        $outputPath,
    ], 86, 80, 1024, MAX_OUTPUT_BYTES, $transcodeFailure);

    $outputBytes = @filesize($outputPath);
    if ($transcode === null) {
        failVideo();
    }
    if (! is_int($outputBytes) || ! is_file($outputPath)) {
        $transcodeFailure = ['cause' => 'output_missing', 'exit' => 0, 'signal' => null, 'stderr' => 'none'];
        failVideo();
    }
    if ($outputBytes < 1) {
        $transcodeFailure = ['cause' => 'output_empty', 'exit' => 0, 'signal' => null, 'stderr' => 'none'];
        failVideo();
    }
    if ($outputBytes > MAX_OUTPUT_BYTES) {
        $transcodeFailure = ['cause' => 'output_too_large', 'exit' => 0, 'signal' => null, 'stderr' => 'none'];
        failVideo();
    }
    if (is_link($outputPath) || realpath($outputPath) !== $outputPath) {
        $transcodeFailure = ['cause' => 'output_path_invalid', 'exit' => 0, 'signal' => null, 'stderr' => 'none'];
        failVideo();
    }

    $failurePhase = 'output_probe';
    $outputProbe = probe($outputPath, 8);
    if ($outputProbe === null) {
        failVideo();
    }
    $failurePhase = 'output_validation';
    $result = validOutputProbe($outputProbe);
    if ($result === null) {
        failVideo();
    }

    $success = true;
    $json = json_encode([
        'bytes' => $outputBytes,
        'width' => $result['width'],
        'height' => $result['height'],
        'durationSeconds' => $result['duration'],
    ], JSON_THROW_ON_ERROR);
    fwrite(STDOUT, $json);
} catch (Throwable) {
    $success = false;
} finally {
    if (! $success) {
        removePartialOutput($outputPath);
    }
}

if (! $success) {
    reportFailure($failurePhase, $failurePhase === 'transcode' ? ($transcodeFailure ?? ['cause' => 'process_error', 'exit' => null, 'signal' => null, 'stderr' => 'none']) : null);
}
