<?php

declare(strict_types=1);

const MAX_INPUT_BYTES = 20000000;
const MAX_OUTPUT_BYTES = 20000000;
const MAX_IMAGE_PIXELS = 50000000;
const MAX_IMAGE_EDGE = 4096;
const POLICY_DIRECTORY = '/usr/local/share/momento-photo-decoder-policy';
const SRGB_PROFILE_PATH = '/usr/local/share/momento-photo-decoder/sRGB2014.icc';
const SRGB_PROFILE_SHA256 = '384b832de3412066743b52a75ee906b6fb9fb8d9e09e936fc2c43223815c6e0a';

error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

final class InvalidPhotoInput extends RuntimeException {}

function configureImageMagickLimits(): void
{
    $limits = [
        Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_MAP => 512 * 1024 * 1024,
        Imagick::RESOURCETYPE_DISK => 2 * 1024 * 1024 * 1024,
        Imagick::RESOURCETYPE_THREAD => 1,
        Imagick::RESOURCETYPE_TIME => 120,
    ];

    foreach ($limits as $resource => $limit) {
        if (! Imagick::setResourceLimit($resource, $limit) || Imagick::getResourceLimit($resource) > $limit) {
            throw new RuntimeException('Native image limits unavailable.');
        }
    }
}

function supportedFormat(string $format): bool
{
    return in_array(strtoupper($format), ['JPEG', 'PNG', 'WEBP', 'HEIC', 'HEIF'], true);
}

function validateDimensions(int $width, int $height): void
{
    if ($width < 1 || $height < 1 || $width * $height > MAX_IMAGE_PIXELS) {
        throw new InvalidPhotoInput('Invalid image dimensions.');
    }
}

function writeAll(string $bytes): bool
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = fwrite(STDOUT, substr($bytes, $offset));
        if (! is_int($written) || $written < 1) {
            return false;
        }

        $offset += $written;
    }

    return fflush(STDOUT);
}

if ($argc !== 1) {
    fwrite(STDERR, "Invalid decoder invocation.\n");
    exit(64);
}

try {
    if (! extension_loaded('imagick') || getenv('MAGICK_CONFIGURE_PATH') !== POLICY_DIRECTORY) {
        throw new RuntimeException('Native image decoder unavailable.');
    }

    configureImageMagickLimits();

    if (! is_file(SRGB_PROFILE_PATH) || hash_file('sha256', SRGB_PROFILE_PATH) !== SRGB_PROFILE_SHA256) {
        throw new RuntimeException('sRGB profile unavailable.');
    }
    $srgbProfile = file_get_contents(SRGB_PROFILE_PATH);
    if (! is_string($srgbProfile) || $srgbProfile === '') {
        throw new RuntimeException('sRGB profile unavailable.');
    }

    $input = stream_get_contents(STDIN, MAX_INPUT_BYTES + 1);
    if (! is_string($input)) {
        throw new RuntimeException('Input stream failed.');
    }
    if ($input === '' || strlen($input) > MAX_INPUT_BYTES) {
        throw new InvalidPhotoInput('Invalid image size.');
    }

    $probe = new Imagick;
    try {
        if (! $probe->pingImageBlob($input) || $probe->getNumberImages() !== 1) {
            throw new InvalidPhotoInput('Invalid still image.');
        }
        if (! supportedFormat($probe->getImageFormat())) {
            throw new InvalidPhotoInput('Unsupported image format.');
        }
        validateDimensions($probe->getImageWidth(), $probe->getImageHeight());
    } catch (ImagickException $exception) {
        throw new InvalidPhotoInput('Invalid image data.');
    } finally {
        $probe->clear();
    }

    $image = new Imagick;
    try {
        try {
            if (! $image->readImageBlob($input) || $image->getNumberImages() !== 1) {
                throw new InvalidPhotoInput('Invalid still image.');
            }
            if (! supportedFormat($image->getImageFormat())) {
                throw new InvalidPhotoInput('Unsupported image format.');
            }
            validateDimensions($image->getImageWidth(), $image->getImageHeight());
            if (! $image->autoOrient() || ! $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT)) {
                throw new RuntimeException('Image orientation failed.');
            }
        } catch (ImagickException $exception) {
            throw new InvalidPhotoInput('Invalid image data.');
        }

        $profiles = $image->getImageProfiles('icc', false);
        if ($profiles !== []) {
            if (! $image->profileImage('icc', $srgbProfile)) {
                throw new RuntimeException('Color conversion failed.');
            }
        } else {
            if (! $image->transformImageColorspace(Imagick::COLORSPACE_SRGB)
                || ! $image->profileImage('icc', $srgbProfile)) {
                throw new RuntimeException('Color conversion failed.');
            }
        }

        if (max($image->getImageWidth(), $image->getImageHeight()) > MAX_IMAGE_EDGE
            && ! $image->thumbnailImage(MAX_IMAGE_EDGE, MAX_IMAGE_EDGE, true)) {
            throw new RuntimeException('Image resize failed.');
        }

        $image->stripImage();
        if (! $image->setImageDepth(8)
            || ! $image->setImageFormat('WEBP')
            || ! $image->setImageCompressionQuality(90)) {
            throw new RuntimeException('WebP encoding failed.');
        }

        $webp = $image->getImageBlob();
    } finally {
        $image->clear();
    }

    if ($webp === '' || strlen($webp) > MAX_OUTPUT_BYTES) {
        throw new RuntimeException('WebP output validation failed.');
    }

    if (! writeAll($webp)) {
        throw new RuntimeException('Output stream failed.');
    }
} catch (InvalidPhotoInput $exception) {
    fwrite(STDERR, "Invalid image.\n");
    exit(65);
} catch (Throwable $exception) {
    fwrite(STDERR, "Image processing failed.\n");
    exit(70);
}
