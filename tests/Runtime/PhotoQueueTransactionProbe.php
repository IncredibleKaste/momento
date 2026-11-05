<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';

final class PhotoQueueTransactionProbeJob implements ShouldQueue
{
    public function __construct(public string $token) {}

    public function handle(): void {}
}

function requireQueueProbe(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function requireQueueProbeEnvironment(): void
{
    $database = getenv('DB_DATABASE');

    requireQueueProbe(getenv('MOMENTO_QUEUE_TRANSACTION_PROBE') === '1', 'Set the explicit queue transaction probe opt-in.');
    requireQueueProbe(getenv('APP_ENV') === 'testing', 'The probe only runs in the testing environment.');
    requireQueueProbe(getenv('DB_CONNECTION') === 'pgsql', 'The probe requires PostgreSQL; it does not skip on another driver.');
    requireQueueProbe(is_string($database) && preg_match('/^momento_[a-z0-9_]*test$/', $database) === 1, 'The database name must be an isolated momento_*test database.');
    requireQueueProbe(getenv('QUEUE_CONNECTION') === 'database', 'The configured default queue must be database.');
    requireQueueProbe(in_array(getenv('DB_QUEUE_CONNECTION') ?: 'pgsql', ['pgsql'], true), 'The database queue must use the PostgreSQL connection.');
}

function isLocalQueueProbeHost(mixed $host): bool
{
    if (! is_string($host)) {
        return false;
    }

    return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
        || (str_starts_with($host, DIRECTORY_SEPARATOR) && is_dir($host));
}

function insertQueueProbeState(Connection $connection, string $email): void
{
    $connection->table('users')->insert([
        'name' => 'Synthetic F07 queue transaction probe',
        'email' => $email,
        'password' => 'synthetic-probe-only',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function queueProbeCount(Connection $connection, string $table, string $column, string $value): int
{
    return $connection->table($table)->where($column, $value)->count();
}

try {
    requireQueueProbeEnvironment();
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: preflight ('.get_class($exception).')'.PHP_EOL);
    exit(1);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expectedDatabase = getenv('DB_DATABASE');
$writer = null;
$observer = null;
$emails = [];
$queues = [];
$stage = 'preflight';
$exitCode = 0;

try {
    requireQueueProbe(extension_loaded('pdo_pgsql'), 'The PostgreSQL PDO extension is required; no skipped pass.');
    $writer = DB::connection('pgsql');
    requireQueueProbe(config('app.env') === 'testing', 'The bootstrapped application is not in testing mode.');
    requireQueueProbe(config('database.default') === 'pgsql', 'The configured default database is not PostgreSQL.');
    requireQueueProbe(isLocalQueueProbeHost($writer->getConfig('host')), 'The resolved PostgreSQL host must be loopback or an absolute Unix socket directory.');
    requireQueueProbe($writer->getDatabaseName() === $expectedDatabase, 'Resolved PostgreSQL database name does not match the isolated DB_DATABASE name.');
    $connectedDatabase = $writer->selectOne('select current_database() as database_name')->database_name ?? null;
    requireQueueProbe($connectedDatabase === $expectedDatabase, 'Connected PostgreSQL database does not match the isolated DB_DATABASE name.');
    requireQueueProbe(config('queue.default') === 'database', 'The configured default queue is not database.');
    requireQueueProbe(config('queue.connections.database.connection') === null || config('queue.connections.database.connection') === 'pgsql', 'The database queue points to a different connection.');
    requireQueueProbe(config('queue.connections.database.table') === 'jobs', 'The database queue must use the existing jobs table.');
    requireQueueProbe(config('queue.connections.database.after_commit') === false, 'The database queue must enqueue immediately inside the transaction.');

    $observerConfig = config('database.connections.pgsql');
    config(['database.connections.f07_probe_observer' => $observerConfig]);
    $observer = DB::connection('f07_probe_observer');
    $queue = Queue::connection('database');

    requireQueueProbe($queue instanceof DatabaseQueue, 'The resolved database queue is not Laravel DatabaseQueue.');
    requireQueueProbe($writer->getPdo() !== $observer->getPdo(), 'The observer must use an independent PostgreSQL connection.');
    requireQueueProbe($queue->getDatabase() === $writer, 'The configured database queue does not use the writer connection.');
    requireQueueProbe($queue->getDatabase()->getPdo() === $writer->getPdo(), 'The queue and state writes do not share the same PostgreSQL session.');
    requireQueueProbe($writer->getSchemaBuilder()->hasTable('users') && $writer->getSchemaBuilder()->hasTable('jobs'), 'The existing users and jobs tables are required; migrations are never run by this probe.');

    $token = bin2hex(random_bytes(16));
    $email = 'f07-queue-probe+'.$token.'@example.test';
    $queueName = 'f07-probe-'.$token;
    $emails[] = $email;
    $queues[] = $queueName;

    $stage = 'uncommitted visibility';
    $writer->beginTransaction();
    insertQueueProbeState($writer, $email);
    $jobId = $queue->push(new PhotoQueueTransactionProbeJob($token), '', $queueName);
    requireQueueProbe(is_numeric($jobId), 'DatabaseQueue did not return a persisted job identifier.');
    requireQueueProbe(queueProbeCount($writer, 'users', 'email', $email) === 1, 'The writer cannot see its uncommitted synthetic state.');
    requireQueueProbe($writer->table('jobs')->where('id', $jobId)->where('queue', $queueName)->exists(), 'The writer cannot see its uncommitted DatabaseQueue job.');
    requireQueueProbe(queueProbeCount($observer, 'users', 'email', $email) === 0, 'The independent observer saw uncommitted state.');
    requireQueueProbe(queueProbeCount($observer, 'jobs', 'queue', $queueName) === 0, 'The independent observer saw an uncommitted job.');
    $writer->commit();
    requireQueueProbe(queueProbeCount($observer, 'users', 'email', $email) === 1, 'The committed synthetic state is not visible to the observer.');
    requireQueueProbe(queueProbeCount($observer, 'jobs', 'queue', $queueName) === 1, 'The committed DatabaseQueue job is not visible to the observer.');
    echo "PASS: writer-only visibility before commit; state and job visible after commit\n";

    $token = bin2hex(random_bytes(16));
    $email = 'f07-queue-probe+'.$token.'@example.test';
    $queueName = 'f07-probe-'.$token;
    $emails[] = $email;
    $queues[] = $queueName;

    $stage = 'rollback';
    $writer->beginTransaction();
    insertQueueProbeState($writer, $email);
    $queue->push(new PhotoQueueTransactionProbeJob($token), '', $queueName);
    requireQueueProbe(queueProbeCount($writer, 'users', 'email', $email) === 1, 'The writer cannot see rollback scenario state.');
    requireQueueProbe(queueProbeCount($writer, 'jobs', 'queue', $queueName) === 1, 'The writer cannot see the rollback scenario job.');
    requireQueueProbe(queueProbeCount($observer, 'users', 'email', $email) === 0, 'The observer saw state before rollback.');
    requireQueueProbe(queueProbeCount($observer, 'jobs', 'queue', $queueName) === 0, 'The observer saw a job before rollback.');
    $writer->rollBack();
    requireQueueProbe(queueProbeCount($observer, 'users', 'email', $email) === 0, 'Rollback left synthetic state visible.');
    requireQueueProbe(queueProbeCount($observer, 'jobs', 'queue', $queueName) === 0, 'Rollback left a queued job visible.');
    echo "PASS: rollback removes both synthetic state and queued job\n";

    $token = bin2hex(random_bytes(16));
    $email = 'f07-queue-probe+'.$token.'@example.test';
    $queueName = str_repeat('q', 256).'-'.$token;
    $emails[] = $email;
    $queues[] = $queueName;

    $stage = 'enqueue failure rollback';
    $writer->beginTransaction();
    insertQueueProbeState($writer, $email);
    $enqueueFailed = false;
    try {
        $queue->push(new PhotoQueueTransactionProbeJob($token), '', $queueName);
    } catch (QueryException $exception) {
        $enqueueFailed = ($exception->errorInfo[0] ?? null) === '22001';
        $writer->rollBack();
    }
    requireQueueProbe($enqueueFailed, 'The overlength queue name did not fail with PostgreSQL SQLSTATE 22001.');
    requireQueueProbe(queueProbeCount($observer, 'users', 'email', $email) === 0, 'Enqueue failure left associated synthetic state visible.');
    requireQueueProbe(queueProbeCount($observer, 'jobs', 'queue', $queueName) === 0, 'Enqueue failure left a queue row visible.');
    echo "PASS: PostgreSQL enqueue failure rolls back associated synthetic state\n";

    echo "QUALIFICATION: synthetic configured PostgreSQL database-queue transaction capability only; no photo intake or worker implementation tested\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$stage.' ('.get_class($exception).')'.PHP_EOL);
    $exitCode = 1;
} finally {
    if ($writer instanceof Connection) {
        if ($writer->transactionLevel() > 0) {
            $writer->rollBack(0);
        }

        foreach ($queues as $queueName) {
            $writer->table('jobs')->where('queue', $queueName)->delete();
        }

        foreach ($emails as $email) {
            $writer->table('users')->where('email', $email)->delete();
        }
    }

    DB::purge('f07_probe_observer');
}

if ($exitCode !== 0) {
    exit($exitCode);
}
