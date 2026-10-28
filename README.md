# Momento

Momento is a photo-sharing application in development. The intended community allows open signup with verification; photos are intended for the owner and approved followers only.

## Current state

The Laravel + Inertia + React starter is implemented, including local authentication, email verification, password recovery and profile settings. Optional two-factor authentication and passkeys are adopted for local accounts; their combined policy and browser flows still require verification. Photo uploads, approved-follow relationships, the private feed and external identity providers are not implemented. E01 adds a Docker/runtime candidate; deployment is not yet verified.

The application requires PostgreSQL, Redis and private media storage. Microsoft is the selected required external provider; SWITCH is stretch. The checked-in development example still uses SQLite and database cache; tests use SQLite and in-memory sessions/cache. Do not treat starter defaults as the target deployment architecture.

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

- [Docker runtime, health and configuration](docs/runtime.md)
- [Architecture and implemented boundaries](docs/architecture.md)
- [Authentication requirements and adoption decisions](docs/authentication-plan.md)
- [Assessment evidence and outstanding gaps](docs/workflow-assessment.md)
- [Delivery proposal](docs/project-review.md)
- [Security, HA and UI proposal](docs/security-ha-ui-proposal.md)

All real infrastructure information and internal operator notes stay outside this repository. Follow the committed [confidentiality rule](.ai/rules/general.md) before publishing code, documentation or artifacts.
