<?php

namespace App\Services;

use App\Exceptions\ConversationVideoException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ConversationVideoDecoder
{
    public function isAvailable(): bool
    {
        $path = config('conversation_attachments.video_decoder_executable');
        if (! is_string($path) || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return false;
        }

        $resolvedPath = realpath($path);

        return $resolvedPath === $path && is_file($path) && is_executable($path);
    }

    /** @return array{bytes: int, width: int, height: int, durationSeconds: float} */
    public function decode(string $inputPath, string $outputPath): array
    {
        if (! $this->isAvailable() || ! $this->validInput($inputPath) || ! $this->validOutputPath($outputPath)) {
            throw new ConversationVideoException;
        }

        $process = new Process(
            [config('conversation_attachments.video_decoder_executable'), $inputPath, $outputPath],
            env: $this->isolatedEnvironment(),
            timeout: config('conversation_attachments.video_decoder_timeout_seconds'),
        );
        $process->disableOutput();
        $stdout = '';
        $outputTooLarge = false;

        try {
            $process->start(function (string $type, string $data) use ($process, &$stdout, &$outputTooLarge): void {
                if ($type !== Process::OUT) {
                    return;
                }

                if (strlen($stdout) + strlen($data) > 4096) {
                    $outputTooLarge = true;
                    $process->stop(0);

                    return;
                }

                $stdout .= $data;
            });
            $process->wait();
        } catch (ProcessTimedOutException) {
            throw new ConversationVideoException;
        } catch (Throwable) {
            throw new ConversationVideoException;
        }

        if ($outputTooLarge || $process->getExitCode() !== 0 || ! $this->validOutputPath($outputPath, allowExisting: true)) {
            throw new ConversationVideoException;
        }

        try {
            $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ConversationVideoException;
        }

        if (! is_array($result)
            || array_keys($result) !== ['bytes', 'width', 'height', 'durationSeconds']
            || ! is_int($result['bytes'])
            || ! is_int($result['width'])
            || ! is_int($result['height'])
            || (! is_float($result['durationSeconds']) && ! is_int($result['durationSeconds']))) {
            throw new ConversationVideoException;
        }

        $actualBytes = @filesize($outputPath);
        $duration = (float) $result['durationSeconds'];
        if (! is_int($actualBytes)
            || $actualBytes < 1
            || $actualBytes > config('conversation_attachments.video_max_output_bytes')
            || $actualBytes !== $result['bytes']
            || $result['width'] < 1
            || $result['height'] < 1
            || max($result['width'], $result['height']) > 1440
            || ! is_finite($duration)
            || $duration <= 0
            || $duration > config('conversation_attachments.video_max_duration_seconds')) {
            throw new ConversationVideoException;
        }

        return [
            'bytes' => $actualBytes,
            'width' => $result['width'],
            'height' => $result['height'],
            'durationSeconds' => $duration,
        ];
    }

    private function validInput(string $path): bool
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path) || ! is_file($path)) {
            return false;
        }

        $resolvedPath = realpath($path);
        $bytes = @filesize($path);

        return $resolvedPath === $path
            && is_int($bytes)
            && $bytes > 0
            && $bytes <= config('conversation_attachments.video_max_bytes');
    }

    private function validOutputPath(string $path, bool $allowExisting = false): bool
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path)) {
            return false;
        }

        $directory = dirname($path);
        if (realpath($directory) !== $directory || ! is_dir($directory)) {
            return false;
        }

        if (! file_exists($path)) {
            return ! $allowExisting;
        }

        return $allowExisting && is_file($path) && realpath($path) === $path;
    }

    /** @return array<string, false> */
    private function isolatedEnvironment(): array
    {
        $environment = getenv();
        $keys = array_merge(
            array_keys($environment),
            array_keys($_ENV),
            array_keys($_SERVER),
        );

        return array_fill_keys(array_unique(array_map(strval(...), $keys)), false);
    }
}
