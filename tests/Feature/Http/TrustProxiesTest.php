<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;

function registerHttpsProxyTestRoutes(): void
{
    Route::group(['as' => 'https.proxy.'], function (): void {
        Route::get('/_proxy-signed', fn () => response()->json(['signed' => true]))
            ->middleware(['web', 'signed'])
            ->name('signed');
    });

    Route::get('/_proxy-context', function (Request $request) {
        return response()->json([
            'ip' => $request->ip(),
            'scheme' => $request->getScheme(),
            'port' => $request->getPort(),
            'host' => $request->getHost(),
            'url' => url('/'),
            'signed_url' => URL::temporarySignedRoute('https.proxy.signed', now()->addMinute()),
        ]);
    })->middleware('web');

    Route::getRoutes()->refreshNameLookups();
}

test('ignores forwarded headers when no proxy is trusted', function () {
    registerHttpsProxyTestRoutes();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Host' => 'spoofed.example.test',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-Prefix' => '/spoofed',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('http://app.example.test/_proxy-context')
        ->assertOk()
        ->assertJsonPath('ip', '198.51.100.24')
        ->assertJsonPath('scheme', 'http')
        ->assertJsonPath('port', 80)
        ->assertJsonPath('host', 'app.example.test')
        ->assertJsonPath('url', 'http://app.example.test');

    config(['trustedproxy.proxies' => ['198.51.100.10']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Host' => 'spoofed.example.test',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-Prefix' => '/spoofed',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('http://app.example.test/_proxy-context')
        ->assertOk()
        ->assertJsonPath('ip', '198.51.100.24')
        ->assertJsonPath('scheme', 'http')
        ->assertJsonPath('port', 80)
        ->assertJsonPath('host', 'app.example.test')
        ->assertJsonPath('url', 'http://app.example.test');
});

test('trusts scheme and client IP only from configured peers and validates HTTPS signed URLs', function () {
    config(['trustedproxy.proxies' => ['198.51.100.10']]);
    registerHttpsProxyTestRoutes();

    $headers = [
        'X-Forwarded-For' => '203.0.113.9',
        'X-Forwarded-Host' => 'spoofed.example.test',
        'X-Forwarded-Port' => '8443',
        'X-Forwarded-Prefix' => '/spoofed',
        'X-Forwarded-Proto' => 'https',
    ];

    $context = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->withHeaders($headers)
        ->get('http://app.example.test/_proxy-context')
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.9')
        ->assertJsonPath('scheme', 'https')
        ->assertJsonPath('port', 8443)
        ->assertJsonPath('host', 'app.example.test')
        ->assertJsonPath('url', 'https://app.example.test:8443')
        ->json();

    expect($context['signed_url'])->toStartWith('https://app.example.test:8443/_proxy-signed?');

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->withHeaders($headers)
        ->get(str_replace('https://', 'http://', $context['signed_url']))
        ->assertOk()
        ->assertExactJson(['signed' => true]);
});

test('defaults production session cookies to secure while local HTTP remains usable', function () {
    $code = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Route::get('/_secure-cookie-probe', function (Illuminate\Http\Request $request) {
    $request->session()->put('https-proxy-test', true);

    return response('ok');
})->middleware('web');
$request = Illuminate\Http\Request::create('http://app.example.test/_secure-cookie-probe');
$request->server->set('REMOTE_ADDR', '127.0.0.1');
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
$cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
echo json_encode($cookie?->isSecure());
PHP;

    $applicationKey = 'base64:'.base64_encode(random_bytes(32));

    foreach (['production' => true, 'local' => false] as $environment => $secure) {
        $process = new Process([
            PHP_BINARY,
            '-r',
            $code,
        ], base_path(), [
            'APP_ENV' => $environment,
            'APP_KEY' => $applicationKey,
            'SESSION_DRIVER' => 'array',
        ]);

        $process->mustRun();

        expect(json_decode($process->getOutput(), true))->toBe($secure);
    }
});
