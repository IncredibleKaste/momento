# Momento

Momento is a photo-sharing application in development. The intended community allows open signup with verification; photos are intended for the owner and approved followers only.

## Current state

The application includes local authentication, two-factor authentication, passkeys, account settings, approved follows, display-name people search, caption-only photo search and mutual-follow text chat. Authorized home and profile photo pages connect to private photo detail, likes, saves, bounded comments and owner deletion. Account email is read-only and whole-account deletion is disabled.

Photo publishing remains unavailable until a qualified isolated decoder is configured. External identity providers, message attachments and message requests remain outside the integrated feature set. Source and runtime checks do not establish deployment, live provider or recovery acceptance.

The interface follows the Instant Memories mockup: paper and charcoal themes, a transparent spectrum navigation rail and restrained corner-ribbon motion. EB Garamond and DM Sans are bundled under their included open-font licenses; builds and browsers do not fetch fonts from a third-party CDN.

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

CI runs source checks, PostgreSQL/Redis integration and native image-runtime qualification. Trusted integration builds also verify the published artifact's privacy and source identity. Live provider, deployment and recovery acceptance are separate checks.

## Documentation

- [Docker runtime, health and configuration](docs/runtime.md)
- [Git and private image delivery](docs/delivery.md)
- [Architecture and implemented boundaries](docs/architecture.md)
- [Authentication requirements and adoption decisions](docs/authentication-plan.md)
- [Assessment evidence and outstanding gaps](docs/workflow-assessment.md)
- [Delivery proposal](docs/project-review.md)
- [Security, HA and UI proposal](docs/security-ha-ui-proposal.md)

All real infrastructure information and internal operator notes stay outside this repository. Follow the committed [confidentiality rule](.ai/rules/general.md) before publishing code, documentation or artifacts.
