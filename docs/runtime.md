# Docker runtime (E01)

The image serves Laravel and built browser assets on port 8080 using PHP 8.5 and FrankenPHP classic mode. Node 22 and Composer run only in build stages. There is no Octane dependency, production Node service, automatic migration or startup key generation.

Build from the repository root:

```sh
docker build --pull -t momento:local .
```

The build needs internet access for locked dependencies, base images and the existing font build. PHP is available during the asset build because Wayfinder generates routes through Artisan. Base tags and extension packages can receive updates; rebuilding is not a promise of identical bytes. Use the verified image digest from the [delivery pipeline summary](delivery.md); rebuilding is not a substitute for pulling that tested artifact.

## Runtime configuration

Supply configuration at runtime using a protected operator-owned environment file outside the checkout/build context, or the deployment secret mechanism. Required values include `APP_KEY`, `APP_URL`, PostgreSQL host/database/user/password and Redis host/credentials. Keep a stable application key across replicas and restarts. Never put real credentials in build arguments or image layers.

The image defaults to production mode, debug off, stderr logging, PostgreSQL database sessions and Redis cache. Configure the actual mail transport before testing verification/recovery emails. Development `.env.example` defaults do not configure a deployed environment.

Verification and password-reset emails use queued notifications. Use an asynchronous queue connection (the default is `database`) and run a worker with the same application configuration:

```sh
php artisan queue:work --tries=3 --timeout=60
```

Mail failures retry with a 60-second backoff, up to three attempts. A request confirmation means delivery was requested; it does not prove inbox delivery. Monitor failed jobs and verify delivery with the configured transport before admitting users. A synchronous queue connection makes mail latency part of public authentication requests and should be reserved for isolated tests.

For a local container smoke test with reachable external PostgreSQL/Redis:

```sh
docker run --rm --name momento --env-file /secure/path/momento.env \
  --mount type=volume,source=momento-media,target=/app/storage/app/private \
  -p 127.0.0.1:8080:8080 momento:local
```

Run migrations once as an explicit deployment step using that same tested image and configuration, before admitting traffic:

```sh
docker exec momento php artisan migrate --force --no-interaction
```

This is a local smoke test using fictional paths and a local volume. Deployment configuration belongs outside this repository. The mounted directory must permit the image's unprivileged user to access private media. Application code stays root-owned; only storage, bootstrap cache and server state directories are writable by the runtime user.

## Health and persistent state

- `/up`: Laravel boot/liveness, independent of database and Redis availability.
- `/ready`: returns generic JSON 200 when the application key is valid, the database session table is readable and Redis answers; otherwise 503. It does not create a session or expose connection details. This is also the container health check.
- PostgreSQL stores durable application data and authoritative sessions. Private media must be mounted outside the image. Redis is cache/rate-limit state, not authoritative authorization state.
- `/storage/*` is denied by the HTTP server; no public storage link is built. Future photo reads still need the product authorization implementation.

Redis connection/read timeouts default to two seconds and are configurable with `REDIS_CONNECT_TIMEOUT`/`REDIS_READ_TIMEOUT`. The image disables Redis connection retries and sets PostgreSQL's connection timeout to two seconds. Upstream request and health-check timeouts remain bounded. These checks are not load or failover proof.

The runtime includes Imagick and explicit Debian HEVC decode/encode plugins for HEIC; merely loading Imagick does not prove those codec delegates exist. CI must prove JPEG, PNG, WebP and HEIC encode/decode capability in the actual image. Full phone orientation, input limits, metadata removal, output quality, quotas and original-file disposal belong to F07/F25; codec capability alone does not establish those behaviors.

## Verification boundary

The container CI job builds on Linux, checks non-root permissions and selected image exclusions, exercises codec round trips, migrates PostgreSQL, renders login, recreates the application with external database/media state, and checks dependency loss/recovery. The source test suite additionally checks readiness failures and absence of a session cookie.

These checks prove the generic container runtime. They do not establish live hosting, identity-provider login, mail delivery, production storage access, full photo processing or operational recovery. Actual infrastructure and operator procedures remain outside this repository.
