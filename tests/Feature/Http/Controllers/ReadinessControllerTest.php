<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

test('readiness succeeds without a session when the database and Redis are available', function () {
    config(['session.driver' => 'database']);
    Redis::shouldReceive('connection')->with('cache')->once()->andReturnSelf();
    Redis::shouldReceive('ping')->once()->andReturn(true);

    $this->getJson(route('ready'))
        ->assertOk()
        ->assertExactJson(['status' => 'ready'])
        ->assertCookieMissing(config('session.cookie'));
});

test('readiness returns 503 without connection details when Redis is unavailable', function () {
    Redis::shouldReceive('connection')->with('cache')->once()->andThrow(new RuntimeException('private-redis-host'));

    $this->getJson(route('ready'))
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'unavailable']);
});

test('readiness returns 503 when the database is unavailable', function () {
    config(['database.connections.unavailable' => ['driver' => 'sqlite', 'database' => '/nonexistent/momento.sqlite']]);
    $connection = DB::getDefaultConnection();
    DB::setDefaultConnection('unavailable');
    Redis::shouldReceive('connection')->never();

    try {
        $this->getJson(route('ready'))
            ->assertServiceUnavailable()
            ->assertExactJson(['status' => 'unavailable']);
    } finally {
        DB::setDefaultConnection($connection);
    }
});

test('readiness returns 503 when the application key is missing or invalid', function ($key) {
    config(['app.key' => $key]);
    app()->forgetInstance('encrypter');
    Redis::shouldReceive('connection')->never();

    $this->getJson(route('ready'))
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'unavailable']);
})->with(['missing' => '', 'invalid' => 'invalid-key']);

test('readiness returns 503 when the session schema has not been migrated', function () {
    Schema::drop('sessions');
    Redis::shouldReceive('connection')->never();

    $this->getJson(route('ready'))
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'unavailable']);
});

test('liveness remains available without Redis or a database connection', function () {
    $connection = DB::getDefaultConnection();
    DB::setDefaultConnection('unavailable');
    Redis::shouldReceive('connection')->never();

    try {
        $this->get('/up')->assertOk();
    } finally {
        DB::setDefaultConnection($connection);
    }
});
