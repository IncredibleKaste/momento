# Momento

Momento is a photo-sharing application in development. The intended community allows open signup with verification; photos will require authenticated, verified access.

## Current state

The Laravel + Inertia + React starter is implemented, including local authentication, email verification, password recovery and profile settings. Two-factor authentication and passkeys are enabled in the starter; their v1 adoption remains unresolved. Photo uploads, the private feed, external identity providers and deployment are not implemented.

PostgreSQL, Redis and multi-host Swarm are confirmed targets. The checked-in development example still uses SQLite and database cache; tests use SQLite and in-memory sessions/cache. Do not treat starter defaults as the target deployment architecture.

## Development

Use PHP 8.5, Composer and Node.js 22 to match the CI configuration. Install from the committed lockfiles. In a fresh local checkout, `composer setup` installs dependencies, creates the local environment file when absent, generates an application key, runs migrations and builds assets. Review `.env` before connecting real services; do not run setup against an existing shared environment.

Run `composer run dev` for local development. Local mail defaults to logging; actual verification/reset mail delivery is not configured by the example.

Useful checks:

```sh
php artisan test --compact
composer types:check
npm run check
npm run types:check
npm run build
```

These passed in the existing local installation on 2026-09-26 (40 tests, 138 assertions). A fresh install, browser flows, PostgreSQL/Redis integration and live CI were not verified in that assessment.

## Documentation

- [Architecture and implemented boundaries](docs/architecture.md)
- [Authentication requirements and adoption decisions](docs/authentication-plan.md)
- [Assessment evidence and outstanding gaps](docs/workflow-assessment.md)
- [Delivery proposal](docs/project-review.md)
- [Security, HA and UI proposal](docs/security-ha-ui-proposal.md)

The latter two documents are proposals, not deployment evidence. Never commit local credentials or the historical transcript.
