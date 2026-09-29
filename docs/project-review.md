# Momento: project review and delivery plan

> **Current stack and identity scope (2026-09-26):** Laravel + Inertia + React, PostgreSQL, Redis and multi-host Swarm are confirmed. [architecture.md](architecture.md) and [authentication-plan.md](authentication-plan.md) take precedence. The earlier invited-local plus single-tenant-Microsoft-only scope is superseded by personal email/password, SWITCH, company Microsoft and Google; GitHub is tentative and passkeys are later. Open verified signup and multiple approved Microsoft tenants are confirmed. Institutional SWITCHaai has no service registration yet; provider configuration remains pending.
> Review date: 2026-09-23; session guidance reconciled 2026-09-26. This is a delivery proposal, not evidence of implemented behavior. See [the workflow assessment](workflow-assessment.md) for current implementation status.

**Updated direction:** security first, then HA, then scalability, with a small modern UI. See [the security, HA, and UI proposal](security-ha-ui-proposal.md) for the current design; it supersedes the initial scope and session/storage recommendations below. Docker Swarm remains the deployment platform; environment-specific allocations are omitted.

> **Current architecture:** [architecture.md](architecture.md) is authoritative: Laravel owns routes and backend behavior, with React through Inertia. Earlier separate frontend/backend deployment assumptions are superseded.

## What the project is

Momento is a small Instagram-style community app and a practical container/CI/CD project. Users share pictures with captions, browse an all-posts or following feed, like and comment, follow people, and view profiles. The infrastructure and reproducible delivery process are part of the school deliverable.

The historical transcript is not a current implementation specification. Use the architecture and authentication decision documents for the confirmed stack and scope.

Earlier planning proposed GitLab delivery. The implemented repository currently has GitHub Actions; whether GitLab remains required by the school deliverable is unresolved. Confirm the rubric before adding a second pipeline or replacing the existing one.

## Recommended scope

The old five-service count is superseded; select Laravel runtime and frontend packaging after stack confirmation. Proposed v1: photos, captions, a chronological community feed, profiles, likes, and separate local/Microsoft login methods. Defer account linking, comments, following, chat, video, stories, and recommendations to reduce scope. HA is now an explicit priority; see the updated proposal for the multi-host design.

Audience decision updated: open signup with verification. Content requires verified authenticated membership, but any person may join through an allowed signup method. Multiple approved Microsoft tenants are supported; this restricts Microsoft login, not ordinary email/Google membership. Institutional SWITCHaai requires registration. See the authentication plan; the old invitation-only and two-provider scope is superseded.

## Current application architecture

Laravel owns routes, validation, authentication and authorization, and delivers React pages through Inertia. PostgreSQL holds relational data and authoritative sessions; Redis supports rate limits/cache. Photos remain private and require backend authorization. Deployment packaging and provider integrations require separate validation; see architecture.md.

## Corrections before implementation

| Priority              | Issue in the transcript                                                                        | Proposed correction                                                                                                                                                                           |
| --------------------- | ---------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Before publishing     | A client secret appears in plaintext                                                           | Treat it as exposed; replace it before use. Keep the original transcript out of Git and artifacts. Never bake runtime secrets into images or frontend variables.                              |
| Before authentication | Client ID and tenant ID are presented as identical and accepted as normal                      | Obtain each separately from the actual registration. Do not trust the copied screenshot interpretation.                                                                                       |
| Before authentication | Matching email silently adopts a local account and removes its password                        | Use separate identities/accounts in v1; defer linking. Any future linking must prove control of both accounts. Never silently replace credentials.                                            |
| Before authentication | Bearer JWTs, cookies, refresh tokens, and Redis revocation are mixed without one complete flow | For this browser app, use an opaque server session in PostgreSQL with an HttpOnly, Secure, SameSite cookie, CSRF protection, session rotation, expiration, and logout invalidation.           |
| Before uploads        | NGINX serves `/uploads` directly                                                               | Require authorization on every media request. Start with the app serving validated files, or use an authenticated internal NGINX handoff. Unguessable filenames alone are not access control. |
| Before CI             | Version and digest lists are called verified, but no install/build evidence exists here        | Resolve a compatible supported stack, lock it, and prove it builds in a clean pipeline. Do not copy abbreviated digests or assume the transcript's versions exist.                            |
| Before tests          | SQLite is the primary integration-test database despite PostgreSQL deployment                  | Run integration and migration tests against disposable PostgreSQL; include Redis for rate-limit failure behavior.                                                                             |
| Before deployment     | Pull/up/prune is treated as a complete deployment                                              | Add migration sequencing, readiness checks, post-deploy checks, deployment serialization, release retention, and a recovery procedure.                                                        |

Microsoft documents `email` as mutable and unsuitable as an authorization identifier. Identify Entra users by the validated tenant/object pair `(tid, oid)`; do not confuse `oid` and `sub`. Proposed schema: `users`, `local_credentials`, and `external_identities` with a unique `(provider, tenant_id, subject_id)` key. Keep local and Microsoft accounts separate in v1; account linking is deferred. [Microsoft token claims](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference)

Retained protocol requirement, with callback route/package to be chosen for Laravel: use one backend OIDC callback; the historical route was `/api/auth/azure/callback`, with authorization-code flow, PKCE, state, nonce, and issuer/audience/signature/expiry validation through a maintained library. Register exact local and production redirect URIs. Keep the Microsoft client secret and Graph access token on the backend. No Laravel OIDC package is selected.

Graph avatars require `GET /me/photo/$value`, which returns image bytes, rather than expecting a public photo URL from `/me`. Fetch with the delegated token, validate and store a bounded local copy, and use initials if no photo exists or Graph is unavailable. Avatar failure must not break login. [Microsoft Graph photo API](https://learn.microsoft.com/en-us/graph/api/profilephoto-get?view=graph-rest-1.0)

For local password handling, select and verify Laravel-native hashing/authentication facilities after stack confirmation. Retain generic login errors and throttling. Invitation delivery, email verification and password recovery still need decisions; SMTP is a dependency if email delivery is required.

## Application details that make the demo reliable

- Validate decoded images, byte size, pixel count, and supported format; reject SVG for the first version. Re-encode images, remove EXIF/GPS metadata, generate thumbnails, and use random storage names. Align proxy body limits with multipart overhead and app limits.
- Enforce ownership for deletion of posts/comments; include basic moderation and account disabling before inviting real users. Escape captions/comments and never render supplied HTML.
- Use database constraints for unique likes/follows, reject self-follow, index feed queries, and paginate with stable `(created_at, id)` cursors. Repeating a like request should not toggle it off accidentally.
- Define cleanup when upload or database writes fail and when posts/accounts are deleted. Store file keys, not user-controlled filesystem paths.
- Provide loading, empty, error, and expired-session states, mobile layout, keyboard access, and accessible control labels. Include a deterministic demo-data script using synthetic users and images.
- Separate `/api/healthz` for process liveness from `/api/readyz` for required database/Redis connectivity. Log request IDs and operational errors without tokens, cookies, or password fields.

## GitLab workflow and version pinning

Use short-lived feature branches and merge requests into protected `main`. Require the validation pipeline before merge. Identify releases with `vX.Y.Z` tags; retain the commit SHA, image digests, and migration revision for every deployed release.

1. **Validate:** formatting/lint and static checks for the confirmed Laravel/frontend stack, Compose configuration validation, secret scan.
2. **Test:** backend integration tests with PostgreSQL/Redis, frontend tests, clean-schema migration and upgrade-from-previous-release checks.
3. **Build:** build app and frontend images once, using pinned bases and locked dependencies. Version the reverse-proxy configuration with the release too.
4. **Inspect:** dependency/image vulnerability scan and an SBOM; define how findings block releases or receive documented exceptions.
5. **Publish:** push approved main/release images under `$CI_REGISTRY_IMAGE`; record immutable digests. Merge-request code must not receive production credentials or deploy access.
6. **Deploy:** once a host exists, automatically deploy approved releases to the demo environment, serialize using a GitLab `resource_group`, and reject obsolete queued deployments. Keep any production environment separately protected.
7. **Verify:** wait for readiness and run external HTTPS checks plus an authenticated smoke test. Preserve the previous release until the new one is proven healthy.

GitLab documents both registry publication and serialized deployment. Use its supplied registry variables rather than assuming `registry.gitlab.com` or inventing an organizational registry hostname. The runner's supported executor/build method must be confirmed before choosing Docker-in-Docker; do not assume privileged mode is available. [Registry workflow](https://docs.gitlab.com/user/packages/container_registry/build_and_push_images/), [deployment safety](https://docs.gitlab.com/ci/environments/deployment_safety/), [runner security](https://docs.gitlab.com/runner/security/)

Pin full image digests in `FROM` and Compose `image` references. The transcript incorrectly claims build arguments cannot be used in `FROM`: a global `ARG` before `FROM` is supported. Pulling a digest then retagging it locally is not a reliable replacement for pinning the build definition. [Dockerfile ARG/FROM reference](https://docs.docker.com/reference/dockerfile/#understand-how-arg-and-from-interact)

The Laravel implementation will need committed dependency locks and compatible pinned PHP/Composer tooling; frontend package tooling depends on the UI decision. Verify clean builds and reviewed dependency updates. Deploy immutable artifacts; exact images and versions remain unselected.

Build/push without a configured deployment is completed CI and artifact publication, not demonstrated end-to-end CD. Report those milestones separately.

## the organization/hosting platform deployment inputs to confirm

These are missing inputs, not established facts about the deployment environment:

- Assigned project/VM, operating system, architecture, quotas, Docker permission, and who operates it.
- GitLab project namespace, enabled registry URL, runner tags/executor, network access, and available CI features.
- DNS name, TLS termination/certificate responsibility, inbound firewall rules, and whether external users can reach the site or need VPN access.
- Outbound access from the app to Entra/Graph and from build jobs to package/image registries; any proxy or certificate requirements.
- Persistent disk paths/capacity, backup destination, retention, recovery owner, and end-of-course deletion date.
- Entra tenant/app registration, permitted users/guests, consent for Graph, and exact callback URL.

Store the host's registry pull credential with read-only scope; keep build credentials in CI. Restrict deployment credentials to the relevant environment, verify SSH host keys, and avoid exposing the Docker API. Docker administration is a privileged capability even when the SSH account is not named root.

Run database migrations once per deployment, before switching to the new application, rather than in every app worker startup. Prefer backward-compatible schema changes. A failed migration should stop promotion; reverting only an image does not undo a database migration.

Back up PostgreSQL and uploads as one recoverable dataset. A simple school-demo procedure can pause writes, dump the database, copy uploads, and then resume service. Store the backup off the VM and prove restoration on an empty environment. Retain release images intentionally instead of pruning immediately after deployment. Suggested discussion targets: at most 24 hours of lost data and recovery within two hours; these are proposed goals, not measured guarantees.

## Milestones and assessment evidence

| Milestone                | Deliverable                                                                     | Evidence of completion                                                                                                               |
| ------------------------ | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| 1. Foundation            | Confirmed Laravel runtime topology, configuration, migrations, basic UI         | Fresh checkout starts; only proxy ports are exposed; data survives restart                                                           |
| 2. Vertical slice        | Local login, image upload, all-posts feed                                       | Browser walkthrough; unauthenticated media denied; second user cannot delete first user's post                                       |
| 3. Community             | Likes and profiles; comments/follows deferred                                   | Two-user tests demonstrate ownership and unique likes                                                                                |
| 4. Entra                 | Optional Microsoft login with separate accounts, Graph avatar; linking deferred | Actual tenant browser login; wrong-tenant rejection; same email never merges accounts; no-photo fallback; logout invalidates session |
| 5. CI and registry       | Locked builds and GitLab publication                                            | Failed test blocks publication; successful commit produces traceable app/frontend digests                                            |
| 6. hosting platform CD   | Automated deployment, HTTPS and smoke checks                                    | GitLab deployment record corresponds to the live release; failed readiness blocks success                                            |
| 7. HA                    | Multi-host deployment and dependency failover                                   | Host-loss drill; database promotion preserves revocation; measured recovery time                                                     |
| 8. Recovery and handover | Backup/restore, rollback instructions, operator README                          | Restore users/posts/images to a clean environment; recover previous compatible release                                               |

Deliver a short architecture explanation, threat model, test matrix, screenshots or demo recording, pipeline links, release inventory, and an honest limitations list. Map these to the actual school rubric once available. A concise, repeatable live demonstration will provide stronger evidence than a long list of proposed technologies.

## Historical verification boundary of the original review

For current status, see architecture.md and workflow-assessment.md. The Laravel starter now exists and local checks pass; product features and deployment remain pending.

The source folder contained only the conversation document. It has been renamed from `instaklone` to `momento`; the historical transcript has subsequently been sanitized; it is not a byte-for-byte original. This review and Git ignore rules were added. No runtime/package inventory, dependency installation, Docker build, application test, Entra login, GitLab pipeline, or hosting platform deployment has been verified during this review. No exact dependency version or image digest is endorsed here.
