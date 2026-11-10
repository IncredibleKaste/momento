<?php

namespace App\Services;

use App\Enums\PhotoPostFailure;
use App\Exceptions\PhotoDecoderException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class PhotoDecoder
{
    public function isAvailable(): bool
    {
        $path = config('photo_processing.decoder_executable');
        if (! is_string($path) || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return false;
        }

        $resolvedPath = realpath($path);

        return $resolvedPath === $path && is_file($path) && is_executable($path);
    }

    public function decode(string $inputPath, string $outputPath): int
    {
        if (! $this->isAvailable()) {
            throw new PhotoDecoderException(PhotoPostFailure::DecoderUnavailable);
        }

        $inputBytes = @filesize($inputPath);
        if (! is_int($inputBytes) || $inputBytes < 1 || $inputBytes > config('photo_processing.max_input_bytes')) {
            throw new PhotoDecoderException(PhotoPostFailure::InvalidPhoto);
        }

        $input = @fopen($inputPath, 'rb');
        $output = @fopen($outputPath, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
        }

        $process = new Process(
            [config('photo_processing.decoder_executable')],
            env: $this->isolatedEnvironment(),
            input: $input,
            timeout: config('photo_processing.decoder_timeout_seconds'),
        );
        $process->disableOutput();
        $outputBytes = 0;
        $outputTooLarge = false;
        $outputWriteFailed = false;

        try {
            $process->start(function (string $type, string $data) use (
                $process,
                $output,
                &$outputBytes,
                &$outputTooLarge,
                &$outputWriteFailed,
            ): void {
                if ($type !== Process::OUT) {
                    return;
                }

                if ($outputBytes + strlen($data) > config('photo_processing.max_output_bytes')) {
                    $outputTooLarge = true;
                    $process->stop(0);

                    return;
                }

                $offset = 0;
                $length = strlen($data);
                while ($offset < $length) {
                    $written = @fwrite($output, substr($data, $offset));
                    if (! is_int($written) || $written < 1) {
                        $outputWriteFailed = true;
                        $process->stop(0);

                        return;
                    }
                    $offset += $written;
                }

                $outputBytes += $length;
            });
            $process->wait();
        } catch (ProcessTimedOutException) {
            throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
        } catch (Throwable) {
            throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($process->getExitCode() === 65) {
            throw new PhotoDecoderException(PhotoPostFailure::InvalidPhoto);
        }

        if ($outputTooLarge || $outputWriteFailed || $process->getExitCode() !== 0 || $outputBytes < 1) {
            throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
        }

        return $outputBytes;
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
