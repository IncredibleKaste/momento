# Application architecture

Momento uses Laravel with Inertia and React. Laravel owns routing, authentication, validation and authorization; browser assets ship with the application. No separate browser bearer-token API or production Node service is required.

## Application contracts

- PostgreSQL stores application records and authoritative sessions. Redis provides shared cache and rate limits; it does not decide durable authorization.
- Open local signup requires email verification. Optional local two-factor authentication and passkeys are adopted, with their combined policy and browser flows still requiring feature acceptance.
- External identities use validated provider/issuer/subject identifiers. Never link accounts or add local credentials merely because email addresses match.
- Profiles expose only the allowed identity fields. Photos and captions are restricted to the owner and approved followers, with authorization required on every read.
- Uploaded media requires bounded decoding, orientation handling, metadata removal, private storage and safe cleanup. The Docker image supplies codec capability; the product pipeline is a separate feature.

## Runtime

The [Docker runtime](runtime.md) serves Laravel and built browser assets using PHP 8.5 and FrankenPHP classic mode. Configuration and durable state are external to the image. Liveness and dependency readiness are separate.

## Current implementation boundary

The authentication/settings starter and Docker runtime exist. Follow requests support owner approval, decline, cancellation, unfollow and follower removal. Each new request after removal requires fresh approval; photo-access checks query the current relationship. Member links expose only display names and route identity to verified members.

Photo posting, actual media delivery, the authorized feed and external-provider integrations remain separate. The shared follow predicate is covered with synthetic private resources; downstream photo routes must call it on every read. Source tests do not establish live authentication, storage or deployment acceptance.

Use the [authentication plan](authentication-plan.md) for identity policy and [verification record](workflow-assessment.md) for scoped evidence. Actual deployment infrastructure, network and storage arrangements are confidential and are documented outside this repository. Do not infer a hosted topology or availability guarantee from application code.
