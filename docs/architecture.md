# Current architecture: Laravel + Inertia + React

Decision date: 2026-09-26. Status: application stack confirmed by user; open verified signup, multiple approved Microsoft tenants and institutional SWITCHaai confirmed; provider setup remains pending.

This document supersedes earlier application architectures. Laravel is the application/backend framework. React is explicitly confirmed through Inertia. Laravel owns routing, controllers, validation and authorization; this is not a separate React SPA/API architecture. The installed baseline assessed on 2026-09-26 uses PHP 8.5.11, Laravel 13.33.0, Inertia Laravel 3.4.0 and Fortify 1.40.0. Lockfiles record dependency versions; production runtime images remain unselected.

## Confirmed and pending

| Area                 | Status                                                                                                                                                                                                                                               |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Application/backend  | Laravel, confirmed                                                                                                                                                                                                                                   |
| Frontend             | React through Inertia, confirmed; Laravel owns routes/backend                                                                                                                                                                                        |
| Data and sessions    | PostgreSQL confirmed; retain authoritative database-backed sessions and revocation requirements                                                                                                                                                      |
| Cache/rate limits    | Redis confirmed for rate limits/cache; not authoritative security state                                                                                                                                                                              |
| Identity             | Personal email/password, SWITCH AAI, company Microsoft, Google requested; GitHub tentative; passkeys later. Open verified signup and approved Microsoft tenants confirmed; SWITCH registration absent; no automatic linking; see authentication plan |
| Photos               | Private storage, authorization on every read, bounded processing, metadata removal and cleanup requirements retained; provider/library pending                                                                                                       |
| Deployment           | multi-host Swarm target confirmed; not provisioned or verified; runtime/container count pending                                                                                                                                                      |
| Dependencies/tooling | Starter and lockfiles implemented; local checks pass; fresh installation and live CI remain unverified                                                                                                                                                  |

Conceptual flow: browser → HTTPS ingress → Laravel routes/controllers → Inertia React pages. Laravel accesses PostgreSQL, Redis and private media storage. Authentication callbacks terminate at the backend; provider credentials/tokens must not be serialized into Inertia page props. Use the same-origin Laravel browser session and CSRF boundary. No standalone React API service, browser bearer-token store or separate frontend runtime is required by this design. Asset build tooling is distinct from a production Node service; SSR is not currently required.

[Authentication plan](authentication-plan.md) is authoritative for the expanded provider scope and confirmed admission policy and outstanding provider setup. Earlier two-provider-only plans are superseded.

## Preserved requirements

STANDARD assurance and provisional R2 risk describe the intended capabilities and impact, not the old framework. Preserve server-side authorization, membership/disabled-account enforcement, opaque revocable sessions, cookie/CSRF protections, restricted database authority, upload resource bounds, private files and separate identities. Laravel defaults/packages must be checked against these requirements; choosing a framework is not evidence they are met.

Preserve acceptance expectations: clean migration, invalid/disabled-user denial, session rotation and logout revocation, CSRF denial, cross-user deletion denial, unauthorized media denial, malformed/oversized-image rejection, partial-write cleanup, limiter outage behavior, restore tests and revocation-preserving failover. Health must distinguish process liveness from usable dependencies. Laravel routes and Pest tests now exist; the retained acceptance expectations are not all covered.

## Current repository state

The Laravel starter is committed on `develop` at `d5240d7`. Local signup/login, verification, recovery, profile settings, 2FA and passkey scaffolding exist. Passkeys conflict with the documented deferral; 2FA/passkey release adoption remains unresolved. No photo model, upload/feed flow, account-disable mechanism or external-provider integration exists.

The checked-in example uses SQLite, database sessions/cache and log mail. Tests use SQLite and in-memory sessions/cache; PostgreSQL and Redis remain target integrations. Local tests, PHP static analysis, frontend checks and asset build passed on 2026-09-26; see [assessment evidence](workflow-assessment.md). No browser, deployment or HA verification is claimed.

## Next implementation boundary

The stack clarification is complete: Laravel + Inertia + React, PostgreSQL, Redis and multi-host Swarm. Authentication has expanded, so use open verified signup and an explicit Microsoft tenant allowlist; resolve SWITCH registration/integration and provider-specific verification semantics before enabling those flows. These do not require reopening the framework decision. First reconcile documentation and resolve starter feature adoption. A proposed subsequent increment is PostgreSQL/Redis integration and authentication lifecycle verification, before private photo functionality. Implementation of that increment has not been authorized by the documentation update.

Reference checked 2026-09-26: [Laravel React starter kit](https://laravel.com/docs/13.x/starter-kits). It provides an official starting point using React through Inertia and Laravel authentication; package versions must still be resolved and tested locally.
