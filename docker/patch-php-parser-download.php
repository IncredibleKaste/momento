<?php

declare(strict_types=1);

function failPatch(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

$arguments = $_SERVER['argv'] ?? null;
if (! is_array($arguments) || count($arguments) !== 2) {
    failPatch('Usage: patch-php-parser-download.php /path/to/gen_stub.php');
}

$target = $arguments[1] ?? null;
if (! is_string($target)) {
    failPatch('Usage: patch-php-parser-download.php /path/to/gen_stub.php');
}
if (! is_file($target) || ! is_readable($target) || ! is_writable($target)) {
    failPatch('The PHP stub generator must exist and be readable and writable.');
}

$source = file_get_contents($target);
if (! is_string($source)) {
    failPatch('Cannot read the PHP stub generator.');
}

$versionAnchor = '$version = "5.6.1";';
if (substr_count($source, $versionAnchor) !== 1) {
    failPatch('The PHP stub generator is not the expected PHP-Parser 5.6.1 version.');
}

$downloadBlock = <<<'PHP'
        $tarName = "v$version.tar.gz";
        $downloadUrl = "https://github.com/nikic/PHP-Parser/archive/$tarName";
        passthru("wget -O $tarName $downloadUrl", $exit);
        if ($exit !== 0) {
            passthru("curl -LO $downloadUrl", $exit);
        }
        if ($exit !== 0) {
            throw new Exception("Failed to download PHP-Parser tarball");
        }
PHP;

if (substr_count($source, $downloadBlock) !== 1) {
    failPatch('The PHP stub generator download block changed; refusing an unreviewed patch.');
}

$retryBlock = <<<'PHP'
        $tarName = "v$version.tar.gz";
        $downloadUrl = "https://github.com/nikic/PHP-Parser/archive/$tarName";
        $archiveIsValid = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            @unlink($tarName);
            $downloadCommand = sprintf(
                'curl --fail --location --silent --show-error --connect-timeout 10 --max-time 60 --output %s %s',
                escapeshellarg($tarName),
                escapeshellarg($downloadUrl)
            );
            passthru($downloadCommand, $downloadExit);
            if ($downloadExit === 0) {
                $validationCommand = sprintf('tar -tzf %s >/dev/null', escapeshellarg($tarName));
                passthru($validationCommand, $validationExit);
                if ($validationExit === 0) {
                    $archiveIsValid = true;
                    break;
                }
            }
            if ($attempt < 2) {
                sleep(1);
            }
        }
        if (! $archiveIsValid) {
            @unlink($tarName);
            throw new Exception("Failed to download or validate PHP-Parser tarball after 3 attempts");
        }
PHP;

$updated = str_replace($downloadBlock, $retryBlock, $source, $replacements);
if ($replacements !== 1) {
    failPatch('The PHP stub generator download block was not replaced exactly once.');
}

if (file_put_contents($target, $updated) !== strlen($updated)) {
    failPatch('Cannot write the patched PHP stub generator.');
}

echo 'Patched the PHP-Parser 5.6.1 download guard.'.PHP_EOL;
