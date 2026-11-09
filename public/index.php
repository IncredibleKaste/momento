<?php

use App\Support\PhotoCommentRequestBody;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require_once __DIR__.'/../vendor/autoload.php';

Request::enableHttpMethodParameterOverride();
$baseRequest = SymfonyRequest::createFromGlobals();
$isPhotoCommentPost = PhotoCommentRequestBody::appliesTo($baseRequest);

if ($isPhotoCommentPost) {
    $reject = static function (int $status, string $message): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode(['message' => $message], JSON_THROW_ON_ERROR);
        exit;
    };

    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        $reject(400, 'Unable to read comment request.');
    }

    try {
        $boundedBody = PhotoCommentRequestBody::read($stream);
    } catch (RuntimeException) {
        fclose($stream);
        $reject(400, 'Unable to read comment request.');
    }

    fclose($stream);

    if ($boundedBody === null) {
        $reject(413, 'Comment request is too large.');
    }
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::createFromBase($baseRequest));
