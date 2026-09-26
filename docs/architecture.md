# Current architecture: Laravel + Inertia + React

Decision date: 2026-09-26. Status: application stack confirmed by user; open verified signup, multiple approved Microsoft tenants and institutional SWITCHaai confirmed; provider setup remains pending.

This document supersedes earlier application architectures. Laravel is the application/backend framework. React is explicitly confirmed through Inertia. Laravel owns routing, controllers, validation and authorization; this is not a separate React SPA/API architecture. No exact Laravel/PHP versions, authentication packages or runtime images have been selected.

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
| Dependencies/tooling | PHP/Laravel/Composer and frontend versions, starter kit and CI images must be selected and verified                                                                                                                                                  |

Conceptual flow: browser → HTTPS ingress → Laravel routes/controllers → Inertia React pages. Laravel accesses PostgreSQL, Redis and private media storage. Authentication callbacks terminate at the backend; provider credentials/tokens must not be serialized into Inertia page props. Use the same-origin Laravel browser session and CSRF boundary. No standalone React API service, browser bearer-token store or separate frontend runtime is required by this design. Asset build tooling is distinct from a production Node service; SSR is not currently required.

[Authentication plan](authentication-plan.md) is authoritative for the expanded provider scope and confirmed admission policy and outstanding provider setup. Earlier two-provider-only plans are superseded.

## Preserved requirements

STANDARD assurance and provisional R2 risk describe the intended capabilities and impact, not the old framework. Preserve server-side authorization, membership/disabled-account enforcement, opaque revocable sessions, cookie/CSRF protections, restricted database authority, upload resource bounds, private files and separate identities. Laravel defaults/packages must be checked against these requirements; choosing a framework is not evidence they are met.

Preserve acceptance expectations: clean migration, invalid/disabled-user denial, session rotation and logout revocation, CSRF denial, cross-user deletion denial, unauthorized media denial, malformed/oversized-image rejection, partial-write cleanup, limiter outage behavior, restore tests and revocation-preserving failover. Health must distinguish process liveness from usable dependencies. Exact routes and PHP test tools remain open.

## Current repository state

Obsolete application scaffolding, dependency manifests, test harnesses and local tooling have been removed. Framework-independent risk analysis, health/readiness expectations, security requirements and secret exclusions remain applicable. No Laravel implementation or runtime verification exists yet.

## Next implementation boundary

The stack clarification is complete: Laravel + Inertia + React, PostgreSQL, Redis and multi-host Swarm. Authentication has expanded, so use open verified signup and an explicit Microsoft tenant allowlist; resolve SWITCH registration/integration and provider-specific verification semantics before enabling those flows. These do not require reopening the framework decision. Select compatible maintained tooling and a reproducible foundation next; no new application code was added by this documentation update.

Reference checked 2026-09-26: [Laravel React starter kit](https://laravel.com/docs/13.x/starter-kits). It provides an official starting point using React through Inertia and Laravel authentication; package versions must still be resolved and tested locally.
