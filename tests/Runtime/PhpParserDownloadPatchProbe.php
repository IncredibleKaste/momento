<?php

declare(strict_types=1);

function requireParserPatchProbe(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runParserPatchProbeProcess(array $command, array $environment, ?string $workingDirectory = null): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $workingDirectory, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start a controlled probe process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [
        'exitCode' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function extractParserInstallerFunction(string $source): string
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    $offset = 0;
    $start = null;
    $depth = 0;

    foreach ($tokens as $index => $token) {
        $text = is_array($token) ? $token[1] : $token;
        if ($start === null && is_array($token) && $token[0] === T_FUNCTION) {
            $nameIndex = $index + 1;
            while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex]) && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $nameIndex++;
            }
            if (isset($tokens[$nameIndex]) && $tokens[$nameIndex] === '&') {
                $nameIndex++;
            }
            while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex]) && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $nameIndex++;
            }
            if (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_STRING && $tokens[$nameIndex][1] === 'installPhpParser') {
                $start = $offset;
            }
        }

        if ($start !== null) {
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $offset + strlen($text) - $start);
                }
            }
        }

        $offset += strlen($text);
    }

    throw new RuntimeException('The PHP stub generator does not contain installPhpParser().');
}

function removeParserPatchProbeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

$arguments = $_SERVER['argv'] ?? null;
if (! is_array($arguments) || ! array_is_list($arguments)) {
    fwrite(STDERR, 'Cannot read command arguments.'.PHP_EOL);
    exit(1);
}
$generatorPath = $arguments[1] ?? null;
$patcherPath = $arguments[2] ?? null;
if (! is_string($generatorPath) || ! is_file($generatorPath) || ! is_string($patcherPath) || ! is_file($patcherPath)) {
    fwrite(STDERR, 'Usage: PhpParserDownloadPatchProbe.php /path/to/gen_stub.php /path/to/patcher.php'.PHP_EOL);
    exit(1);
}

$probeDirectory = sys_get_temp_dir().'/momento-php-parser-probe-'.bin2hex(random_bytes(12));
$stage = 'prepare';
$exitCode = 0;

try {
    requireParserPatchProbe(mkdir($probeDirectory, 0700), 'Cannot create a private temporary probe directory.');
    $source = file_get_contents($generatorPath);
    if (! is_string($source)) {
        throw new RuntimeException('Cannot read the PHP stub generator.');
    }
    $baseEnvironment = getenv();

    $changedAnchor = str_replace(
        'https://github.com/nikic/PHP-Parser/archive/$tarName',
        'https://example.invalid/PHP-Parser/archive/$tarName',
        $source,
        $anchorChanges
    );
    requireParserPatchProbe($anchorChanges === 1, 'The expected source anchor is not unique.');
    $changedPath = $probeDirectory.'/changed-gen-stub.php';
    requireParserPatchProbe(file_put_contents($changedPath, $changedAnchor) === strlen($changedAnchor), 'Cannot prepare the changed-anchor probe.');
    $pathBeforePatch = file_get_contents($changedPath);
    if (! is_string($pathBeforePatch)) {
        throw new RuntimeException('Cannot read the changed-anchor probe.');
    }
    $stage = 'changed anchor';
    $changedResult = runParserPatchProbeProcess([PHP_BINARY, $patcherPath, $changedPath], $baseEnvironment);
    requireParserPatchProbe($changedResult['exitCode'] !== 0, 'The patcher accepted a changed source anchor.');
    requireParserPatchProbe(file_get_contents($changedPath) === $pathBeforePatch, 'The failed patch changed the helper file.');
    echo 'PASS: changed source anchor fails closed without modifying the helper'.PHP_EOL;

    $patchedPath = $probeDirectory.'/patched-gen-stub.php';
    requireParserPatchProbe(file_put_contents($patchedPath, $source) === strlen($source), 'Cannot prepare the patch probe copy.');
    $stage = 'patch copy';
    $patchResult = runParserPatchProbeProcess([PHP_BINARY, $patcherPath, $patchedPath], $baseEnvironment);
    requireParserPatchProbe($patchResult['exitCode'] === 0, 'The patcher rejected the current PHP stub generator: '.$patchResult['stderr']);
    $patchedSource = file_get_contents($patchedPath);
    if (! is_string($patchedSource)) {
        throw new RuntimeException('Cannot read the patched helper copy.');
    }
    $installerFunction = extractParserInstallerFunction($patchedSource);

    $validSource = $probeDirectory.'/valid-source';
    $parserRoot = $validSource.'/PHP-Parser-5.6.1';
    requireParserPatchProbe(mkdir($parserRoot, 0700, true), 'Cannot create the valid archive fixture.');
    requireParserPatchProbe(file_put_contents($parserRoot.'/probe.txt', 'valid fixture') === strlen('valid fixture'), 'Cannot write the valid archive fixture.');
    $validArchive = $probeDirectory.'/valid.tar.gz';
    $archiveResult = runParserPatchProbeProcess(['tar', '-czf', $validArchive, '-C', $validSource, 'PHP-Parser-5.6.1'], getenv());
    requireParserPatchProbe($archiveResult['exitCode'] === 0, 'Cannot create the valid archive fixture: '.$archiveResult['stderr']);

    $fakeBin = $probeDirectory.'/bin';
    requireParserPatchProbe(mkdir($fakeBin, 0700), 'Cannot create the controlled downloader directory.');
    $fakeCurl = <<<'PHP'
<?php
$outputPath = null;
foreach ($argv as $index => $argument) {
    if ($argument === '--output') {
        $outputPath = $argv[$index + 1] ?? null;
        break;
    }
}
if (! is_string($outputPath)) {
    exit(90);
}
$attemptsPath = getenv('MOMENTO_PROBE_CURL_ATTEMPTS');
$mode = getenv('MOMENTO_PROBE_CURL_MODE');
$archivePath = getenv('MOMENTO_PROBE_CURL_ARCHIVE');
if (! is_string($attemptsPath) || ! is_string($mode) || ! is_string($archivePath)) {
    exit(91);
}
$attempt = is_file($attemptsPath) ? (int) file_get_contents($attemptsPath) + 1 : 1;
file_put_contents($attemptsPath, (string) $attempt);
if ($mode === 'recover' && $attempt === 2) {
    exit(copy($archivePath, $outputPath) ? 0 : 92);
}
file_put_contents($outputPath, 'short non-archive response');
exit(0);
PHP;
    $fakeCurlPath = $fakeBin.'/curl';
    $fakeCurlScript = '#!'.PHP_BINARY.PHP_EOL.$fakeCurl;
    requireParserPatchProbe(file_put_contents($fakeCurlPath, $fakeCurlScript) === strlen($fakeCurlScript), 'Cannot write the fake curl command.');
    requireParserPatchProbe(chmod($fakeCurlPath, 0700), 'Cannot make the fake curl command executable.');

    $baseEnvironment['PATH'] = $fakeBin.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin');
    $baseEnvironment['MOMENTO_PROBE_CURL_ARCHIVE'] = $validArchive;

    $successDirectory = $probeDirectory.'/recover';
    requireParserPatchProbe(mkdir($successDirectory, 0700), 'Cannot create the recovery test directory.');
    $successHarness = $successDirectory.'/run.php';
    $harnessSource = "<?php\n".$installerFunction."\ntry {\n    installPhpParser('5.6.1', __DIR__.'/PHP-Parser-5.6.1');\n} catch (Throwable \$exception) {\n    fwrite(STDERR, \$exception->getMessage().PHP_EOL);\n    exit(1);\n}\n";
    requireParserPatchProbe(file_put_contents($successHarness, $harnessSource) === strlen($harnessSource), 'Cannot write the recovery harness.');
    $successEnvironment = $baseEnvironment;
    $successEnvironment['MOMENTO_PROBE_CURL_MODE'] = 'recover';
    $successEnvironment['MOMENTO_PROBE_CURL_ATTEMPTS'] = $successDirectory.'/attempts';
    $stage = 'corrupt then valid archive';
    $successResult = runParserPatchProbeProcess([PHP_BINARY, $successHarness], $successEnvironment, $successDirectory);
    requireParserPatchProbe($successResult['exitCode'] === 0, 'The helper did not recover from a corrupt response: '.$successResult['stderr']);
    requireParserPatchProbe(file_get_contents($successEnvironment['MOMENTO_PROBE_CURL_ATTEMPTS']) === '2', 'The helper did not retry exactly once after a corrupt response.');
    requireParserPatchProbe(file_get_contents($successDirectory.'/PHP-Parser-5.6.1/probe.txt') === 'valid fixture', 'The validated archive was not extracted into the expected cache.');
    echo 'PASS: corrupt response is discarded and the next valid archive is cached'.PHP_EOL;

    $failureDirectory = $probeDirectory.'/permanent-failure';
    requireParserPatchProbe(mkdir($failureDirectory, 0700), 'Cannot create the permanent failure test directory.');
    $failureHarness = $failureDirectory.'/run.php';
    requireParserPatchProbe(file_put_contents($failureHarness, $harnessSource) === strlen($harnessSource), 'Cannot write the permanent failure harness.');
    $failureEnvironment = $baseEnvironment;
    $failureEnvironment['MOMENTO_PROBE_CURL_MODE'] = 'permanent';
    $failureEnvironment['MOMENTO_PROBE_CURL_ATTEMPTS'] = $failureDirectory.'/attempts';
    $stage = 'permanent invalid archive';
    $failureResult = runParserPatchProbeProcess([PHP_BINARY, $failureHarness], $failureEnvironment, $failureDirectory);
    requireParserPatchProbe($failureResult['exitCode'] !== 0, 'The helper accepted three corrupt responses.');
    requireParserPatchProbe(file_get_contents($failureEnvironment['MOMENTO_PROBE_CURL_ATTEMPTS']) === '3', 'The helper did not stop after three attempts.');
    requireParserPatchProbe(! is_dir($failureDirectory.'/PHP-Parser-5.6.1'), 'The helper created a cache from corrupt responses.');
    requireParserPatchProbe(! file_exists($failureDirectory.'/v5.6.1.tar.gz'), 'The helper left a corrupt archive behind after permanent failure.');
    echo 'PASS: three corrupt responses fail closed without leaving a parser cache'.PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'PHP-Parser download probe failed at '.$stage.': '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    removeParserPatchProbeDirectory($probeDirectory);
}

exit($exitCode);
