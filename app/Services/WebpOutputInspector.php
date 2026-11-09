<?php

namespace App\Services;

use App\Enums\PhotoPostFailure;
use App\Exceptions\PhotoDecoderException;

class WebpOutputInspector
{
    /** @return array{bytes: int, width: int, height: int} */
    public function inspect(string $path): array
    {
        $bytes = @filesize($path);
        if (! is_int($bytes) || $bytes < 20 || $bytes > config('photo_processing.max_output_bytes') || is_link($path)) {
            throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
        }

        $stream = @fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new PhotoDecoderException(PhotoPostFailure::StorageUnavailable);
        }

        try {
            $header = $this->readExactly($stream, 12);
            if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP' || $this->littleEndian32(substr($header, 4, 4)) + 8 !== $bytes) {
                throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
            }

            $offset = 12;
            $extended = false;
            $alphaFlag = false;
            $alphaChunk = false;
            $imageChunk = null;
            $width = null;
            $height = null;

            while ($offset < $bytes) {
                $chunkHeader = $this->readExactly($stream, 8);
                $offset += 8;
                $kind = substr($chunkHeader, 0, 4);
                $length = $this->littleEndian32(substr($chunkHeader, 4, 4));
                $paddedLength = $length + ($length % 2);
                if ($paddedLength > $bytes - $offset) {
                    throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                }

                if (in_array($kind, ['ICCP', 'EXIF', 'XMP '], true) || ! in_array($kind, ['VP8 ', 'VP8L', 'VP8X', 'ALPH'], true)) {
                    throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                }

                if ($kind === 'VP8X') {
                    if ($extended || $offset !== 20 || $length !== 10) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $payload = $this->readExactly($stream, 10);
                    $flags = ord($payload[0]);
                    if (($flags & ~0x10) !== 0 || substr($payload, 1, 3) !== "\0\0\0") {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $width = 1 + ord($payload[4]) + (ord($payload[5]) << 8) + (ord($payload[6]) << 16);
                    $height = 1 + ord($payload[7]) + (ord($payload[8]) << 8) + (ord($payload[9]) << 16);
                    $alphaFlag = ($flags & 0x10) !== 0;
                    $extended = true;
                } elseif ($kind === 'ALPH') {
                    if (! $extended || ! $alphaFlag || $alphaChunk || $imageChunk !== null || $length < 1) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $alphaChunk = true;
                } elseif ($kind === 'VP8 ') {
                    if ($imageChunk !== null || ($alphaChunk && ! $extended) || $length < 10) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $payload = $this->readExactly($stream, 10);
                    if ((ord($payload[0]) & 1) !== 0 || substr($payload, 3, 3) !== "\x9d\x01\x2a") {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $imageWidth = (ord($payload[6]) | (ord($payload[7]) << 8)) & 0x3FFF;
                    $imageHeight = (ord($payload[8]) | (ord($payload[9]) << 8)) & 0x3FFF;
                    $width ??= $imageWidth;
                    $height ??= $imageHeight;
                    if ($width !== $imageWidth || $height !== $imageHeight) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $imageChunk = $kind;
                    fseek($stream, $length - 10, SEEK_CUR);
                } elseif ($kind === 'VP8L') {
                    if ($imageChunk !== null || $alphaChunk) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    if ($length < 5) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $payload = $this->readExactly($stream, 5);
                    if (ord($payload[0]) !== 0x2F || (ord($payload[4]) & 0xE0) !== 0) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $imageWidth = 1 + ord($payload[1]) + ((ord($payload[2]) & 0x3F) << 8);
                    $imageHeight = 1 + ((ord($payload[2]) & 0xC0) >> 6) + (ord($payload[3]) << 2) + ((ord($payload[4]) & 0x0F) << 10);
                    $width ??= $imageWidth;
                    $height ??= $imageHeight;
                    if ($width !== $imageWidth || $height !== $imageHeight) {
                        throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
                    }
                    $imageChunk = $kind;
                    fseek($stream, $length - 5, SEEK_CUR);
                }

                if ($kind !== 'VP8X' && $kind !== 'VP8 ' && $kind !== 'VP8L') {
                    fseek($stream, $length, SEEK_CUR);
                }
                if ($length % 2 === 1) {
                    fseek($stream, 1, SEEK_CUR);
                }
                $offset += $paddedLength;
            }

            if ($offset !== $bytes || $imageChunk === null || ! is_int($width) || ! is_int($height)
                || ($imageChunk === 'VP8 ' && $alphaFlag !== $alphaChunk)
                || $width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
                throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
            }

            return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function readExactly($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length) {
            $remaining = $length - strlen($value);
            if ($remaining < 1) {
                break;
            }
            $chunk = fread($stream, $remaining);
            if (! is_string($chunk) || $chunk === '') {
                throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
            }
            $value .= $chunk;
        }

        return $value;
    }

    private function littleEndian32(string $value): int
    {
        if (strlen($value) !== 4) {
            throw new PhotoDecoderException(PhotoPostFailure::ProcessingFailed);
        }

        return ord($value[0]) | (ord($value[1]) << 8) | (ord($value[2]) << 16) | (ord($value[3]) << 24);
    }
}
