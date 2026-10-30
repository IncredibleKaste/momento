<?php

declare(strict_types=1);

// Synthetic qualification only; this is not the application's upload processor.
function requireProbe(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function referenceWebp(string $source, string $destination): void
{
    $image = new Imagick($source);
    try {
        requireProbe($image->getNumberImages() === 1, 'Synthetic fixture must be a single still.');
        if ($image->getImageOrientation() === Imagick::ORIENTATION_RIGHTTOP) {
            $image->rotateImage(new ImagickPixel('transparent'), 90);
        }
        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        if (max($image->getImageWidth(), $image->getImageHeight()) > 4096) {
            $image->thumbnailImage(4096, 4096, true);
        }
        $image->stripImage();
        $image->setImageDepth(8);
        $image->setImageFormat('WEBP');
        $image->setImageCompressionQuality(90);
        requireProbe($image->writeImage($destination), 'WebP write failed.');
    } finally {
        $image->clear();
    }
}

$directory = sys_get_temp_dir().'/momento-photo-probe-'.bin2hex(random_bytes(12));
$stage = 'capabilities';
$exitCode = 0;

try {
    requireProbe(extension_loaded('imagick'), 'Imagick is required; no skipped pass.');
    requireProbe(mkdir($directory, 0700), 'Cannot create private probe directory.');
    $limits = [
        Imagick::RESOURCETYPE_MEMORY => 256 * 1024 * 1024,
        Imagick::RESOURCETYPE_MAP => 512 * 1024 * 1024,
        Imagick::RESOURCETYPE_DISK => 2 * 1024 * 1024 * 1024,
        Imagick::RESOURCETYPE_THREAD => 1,
        Imagick::RESOURCETYPE_TIME => 120,
    ];
    foreach ($limits as $resource => $limit) {
        requireProbe(Imagick::setResourceLimit($resource, $limit), 'Cannot set experimental resource bound.');
        $limits[$resource] = Imagick::getResourceLimit($resource);
    }
    echo json_encode([
        'scope' => 'synthetic qualification; camera colour and upload acceptance remain untested',
        'php' => PHP_VERSION,
        'imagick' => phpversion('imagick'),
        'imagemagick' => Imagick::getVersion()['versionString'],
        'delegates' => Imagick::getConfigureOptions('DELEGATES'),
        'pcntl' => extension_loaded('pcntl'),
        'effective_resource_limits' => $limits,
    ], JSON_THROW_ON_ERROR).PHP_EOL;

    $cases = [
        'jpeg-orientation' => ['JPEG', 64, 32, 32, 64, false, true],
        'png-alpha' => ['PNG', 64, 32, 64, 32, true, false],
        'webp-alpha' => ['WEBP', 64, 32, 64, 32, true, false],
        'heic-still' => ['HEIC', 64, 32, 64, 32, false, false],
        'jpeg-50mp' => ['JPEG', 10000, 5000, 4096, 2048, false, false],
    ];
    foreach ($cases as $name => [$format, $width, $height, $expectedWidth, $expectedHeight, $alpha, $rotated]) {
        $stage = $name;
        $started = hrtime(true);
        requireProbe(Imagick::queryFormats($format) !== [], 'Required codec is absent.');
        $source = $directory.'/'.$name.'.'.strtolower($format);
        $destination = $directory.'/'.$name.'-processed.webp';
        $fixture = new Imagick;
        try {
            $fixture->newImage($width, $height, $alpha ? 'transparent' : '#0000ff');
            $draw = new ImagickDraw;
            $draw->setFillColor('#ff0000');
            $draw->rectangle(0, 0, $width / 2 - 1, $height - 1);
            $fixture->drawImage($draw);
            $draw->clear();
            $fixture->setImageFormat($format);
            $fixture->setImageCompressionQuality(90);
            if ($rotated) {
                $description = "synthetic-privacy-canary\0";
                $exif = "Exif\0\0II".pack('vVv', 42, 8, 2)
                    .pack('vvVV', 0x010E, 2, strlen($description), 38)
                    .pack('vvVV', 0x0112, 3, 1, 6)
                    .pack('V', 0).$description;
                $fixture->setImageProfile('exif', $exif);
                $fixture->setImageOrientation(Imagick::ORIENTATION_RIGHTTOP);
                $fixture->setImageProfile('xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="0,0N"/></rdf:RDF></x:xmpmeta>');
                $fixture->setImageProperty('comment', 'synthetic-privacy-canary');
            }
            requireProbe($fixture->writeImage($source), 'Synthetic input write failed.');
        } finally {
            $fixture->clear();
        }
        requireProbe(filesize($source) <= 20000000, 'Synthetic input exceeds accepted byte cap.');
        if ($rotated) {
            $original = new Imagick($source);
            try {
                requireProbe($original->getImageOrientation() === Imagick::ORIENTATION_RIGHTTOP, 'Input orientation canary was not encoded.');
                requireProbe($original->getImageProfiles('exif', false) !== [], 'Input EXIF canary is absent.');
                requireProbe($original->getImageProfiles('xmp', false) !== [], 'Input XMP canary is absent.');
                requireProbe($original->getImageProperty('comment') === 'synthetic-privacy-canary', 'Input comment canary is absent.');
            } finally {
                $original->clear();
            }
        }
        referenceWebp($source, $destination);
        $processed = new Imagick($destination);
        try {
            requireProbe($processed->getImageWidth() === $expectedWidth && $processed->getImageHeight() === $expectedHeight, 'Processed dimensions are incorrect.');
            requireProbe($processed->getImageFormat() === 'WEBP', 'Processed format is incorrect.');
            requireProbe($processed->getImageProfiles('*', false) === [], 'Unexpected retained profile.');
            requireProbe(! $processed->getImageProperty('comment'), 'Unexpected retained comment.');
            requireProbe(! str_contains((string) file_get_contents($destination), 'synthetic-privacy-canary'), 'Privacy canary survived.');
            if ($alpha) {
                requireProbe($processed->getImagePixelColor(63, 31)->getColor(1)['a'] < 0.01, 'Transparency was lost.');
            }
            if ($rotated) {
                $top = $processed->getImagePixelColor(16, 8)->getColor(1);
                $bottom = $processed->getImagePixelColor(16, 56)->getColor(1);
                requireProbe($top['r'] > 0.8 && $top['b'] < 0.2 && $bottom['b'] > 0.8 && $bottom['r'] < 0.2, 'Rotation put the colour markers in the wrong place.');
            }
        } finally {
            $processed->clear();
        }
        $inputBytes = filesize($source);
        requireProbe(unlink($source) && ! file_exists($source), 'Synthetic raw intake was not removed.');
        echo json_encode([
            'case' => $name,
            'result' => 'pass',
            'input_pixels' => $width * $height,
            'input_bytes' => $inputBytes,
            'output_bytes' => filesize($destination),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1000000),
            'duration_scope' => 'fixture generation and reference transform',
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        requireProbe(unlink($destination), 'Processed fixture cleanup failed.');
    }
    $peak = '/sys/fs/cgroup/memory.peak';
    echo json_encode([
        'native_container_peak_bytes' => is_readable($peak) ? (int) file_get_contents($peak) : null,
        'peak_scope' => 'entire synthetic probe including fixture generation',
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Synthetic photo probe failed at '.$stage.': '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file) && ! is_link($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
}

exit($exitCode);
