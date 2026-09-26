# Momento: security, availability, scaling, and UI

> **Current stack and identity scope (2026-09-26):** Laravel + Inertia + React, PostgreSQL, Redis and multi-host Swarm are confirmed. [architecture.md](architecture.md) and [authentication-plan.md](authentication-plan.md) take precedence. The earlier invited-local plus single-tenant-Microsoft-only scope is superseded by personal email/password, SWITCH, company Microsoft and Google; GitHub is tentative and passkeys are later. Open verified signup and multiple approved Microsoft tenants are confirmed. Institutional SWITCHaai has no service registration yet; provider configuration remains pending.
> Proposed design, 2026-09-23; architecture status corrected 2026-09-26. **Laravel is the confirmed application/backend framework.** See [the current architecture](architecture.md). React through Inertia is confirmed; runtime packaging and provider configuration remain open. Security, HA and UI requirements below remain applicable; named infrastructure components remain proposals. Priority: **security → high availability → scalability**, with a deliberately small, polished frontend. This supersedes the earlier recommendation to defer HA. Environment-specific infrastructure allocations are omitted; availability and deployment capabilities require verification.

## Account linking: omit it initially

The earlier invited-local plus single-tenant-Microsoft proposal is superseded. Use open signup with verification and offer personal email/password, institutional SWITCHaai, Microsoft from approved company tenants, and Google. GitHub is tentative; passkeys are later. See [authentication-plan.md](authentication-plan.md) for confirmed policy and provider boundaries. SWITCH registration has not started.

Keep provider identities independent of email and do not automatically merge accounts, transfer posts or replace credentials on an email match. Explicit linking remains deferred. Verified ordinary membership is open; provider authentication still requires application checks for verification, disablement and resource authorization. Microsoft tenant approval does not make this a company-only community because personal signup is allowed.

Keep privileged or company-policy-required roles subject to their required identity policy. A local password, Google login or future passkey must not become a bypass for those roles. Define bounded session lifetimes and revalidation; provider disablement does not instantly invalidate an independent application session without a designed mechanism.

### If linking becomes necessary later

1. Start from **Settings → Sign-in methods → Connect Microsoft** while logged into the local account. Require fresh verification of the existing credential; an old session alone is insufficient.
2. Create a short-lived, single-use link transaction on the server, bound to the user and session. Use a distinct link intent/callback path so an ordinary login callback cannot link accounts.
3. Complete fresh Microsoft authentication using authorization code + PKCE. Validate state, nonce, issuer, audience, signature, expiry, tenant, and the required authentication freshness. Use a maintained OIDC client.
4. Show the selected Microsoft identity for confirmation. Identify it using validated `(tid, oid)`, never email. Reject it if already linked to another user; no automatic merging of two populated accounts.
5. Commit under database uniqueness constraints and a transaction, consume the pending request, rotate the current session, invalidate other sessions as appropriate, and audit/notify without logging tokens.
6. Permit multiple login methods only for ordinary community accounts where either method is explicitly acceptable under the same access policy. If Entra security policy is required, do not retain a weaker local login path. Treat conversion to Entra-only as a separate, explicit migration with recovery planning.
7. Defer self-service unlinking and identity transfer initially. Later unlinking must require fresh authentication, a proven remaining permitted login method, and session invalidation. Email access alone must not merge accounts or reset an Entra-only account.

Suggested tables: `users` (profile, membership, disabled status), `local_credentials`, `external_identities` (unique provider/tenant/object tuple), and `sessions`. Store local email uniqueness within local credentials; display/contact email is not a cross-provider identity key.

Acceptance tests: forged/replayed callback rejected; expired transaction rejected; identity already owned rejected; concurrent linking cannot attach the same identity twice; identical email never links; linking cannot downgrade an Entra-required account; disabled users cannot regain access through either provider.

Sources: [Microsoft identity claims](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference), [OWASP reauthentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html).

## HA must include every required dependency

Proposed failure scope: survive loss of one application host without manual intervention, while preserving authorization and acknowledged durable writes. Whole-site disaster recovery is a separate backup/restore objective. Do not promise an uptime percentage until measurement exists.

| Layer                   | Proposed HA design                                                                                                                                                                          | Important limitation                                                                                                                                                        |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Public entry            | Provider-supported redundant load balancer in front of proxy/ingress replicas                                                                                                               | One proxy VM remains a single point of failure. Confirm the organization load balancing/floating-IP support; do not assume VRRP works.                                      |
| App and frontend        | At least two replicas, distributed across separate hosts; readiness probes and rolling updates                                                                                              | Two containers on one host do not survive host loss. Remaining hosts need spare capacity.                                                                                   |
| PostgreSQL              | Prefer an organizational-operated HA service with documented failover/durability; otherwise a supported operator or Patroni design with quorum, fencing, backups, and operational ownership | A StatefulSet or extra PostgreSQL container does not configure safe database failover.                                                                                      |
| Photos                  | Private provider-operated object storage with documented redundancy, or a demonstrably HA shared storage service                                                                            | Per-host Docker volumes cannot follow app replicas. A single NFS server or single object-storage container just moves the failure point.                                    |
| Sessions/security state | Store authoritative opaque sessions, account disablement, and revocation in PostgreSQL; read security decisions from its writer                                                             | Database failover durability must cover revocations as well as posts. Never restore access through stale caches.                                                            |
| Redis                   | Disposable cache and distributed rate limits, with managed failover if available                                                                                                            | If Redis is unavailable, disable sensitive auth/upload operations unless a tested conservative fallback exists. Existing authenticated reads may continue using PostgreSQL. |
| Entra/Graph             | Existing valid application sessions continue within their normal lifetime; Graph avatar failure uses initials                                                                               | New Microsoft login cannot be guaranteed during an Entra outage. Never invent a password bypass.                                                                            |

The move of sessions from Redis to PostgreSQL is intentional: Redis asynchronous failover can lose acknowledged writes, including a logout/delete. Redis remains useful without owning security-critical state. [Redis Sentinel guarantees](https://redis.io/docs/latest/operate/oss_and_stack/management/sentinel/)

For PostgreSQL, require a synchronous durability/failover policy that does not promote a replica missing acknowledged security changes. Fail closed if the safe writer/quorum is unavailable. This can temporarily reject writes, which is the deliberate security-first tradeoff. Synchronous replication has latency and availability costs; the exact guarantee depends on topology, promotion, and fencing. [PostgreSQL replication tradeoffs](https://www.postgresql.org/docs/18/high-availability.html)

For photos, authorize access through the backend and serve/stream private objects. If short-lived signed URLs are introduced later, document that they remain usable until expiry even after logout; avoid them where immediate revocation is required. Use object keys and a storage interface so local development can still use a local directory. Separate database and object writes need cleanup/reconciliation; they are not one atomic transaction.

## Platform choice

**Deployment platform: Docker Swarm.** Choose and validate an appropriate quorum and workload placement. Spread app/frontend replicas across hosts and retain enough capacity to operate after one host is lost. Compose remains the local-development configuration; deployment uses a separately validated Swarm stack definition, not an assumption that every Compose feature transfers unchanged.

Prefer existing operated HA PostgreSQL, private object storage, and a redundant load balancer if the organization makes them available. If only unmanaged compute is available, record the missing state/ingress HA as a separate implementation work package: choose supported database failover with a three-member coordination quorum and fencing, storage replication with documented write/failure semantics, and a provider-supported redundant entry point. Do not declare the application HA while any of these remains single-host. Exact placement depends on resource sizing and permitted network/storage capabilities.

| Option                      | Good fit                                                                               | Recommendation                                                                                                                                                                                         |
| --------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Docker Compose              | Local development, single-host functional testing                                      | Keep it locally. Label single-host hosting as recovery-capable, not HA.                                                                                                                                |
| Docker Swarm                | Team receives VMs and wants a smaller orchestration platform                           | Preferred self-managed school option if Kubernetes is not a learning requirement. Three managers tolerate one manager loss; use host placement rules and redundant ingress/state services.             |
| Existing managed Kubernetes | the organization offers an operated cluster with ingress, storage, policy, and support | Preferred deployment target if available. Use Deployments, Services, probes, resource limits, host spreading, NetworkPolicies where enforced, and narrowly scoped deployment permissions.              |
| Self-managed Kubernetes     | Operating Kubernetes itself is assessed and time/resources exist                       | A separate infrastructure work package: HA control plane, networking, storage, upgrades, observability, and recovery all need owners.                                                                  |
| Portainer                   | Optional operator UI for Docker/Swarm/Kubernetes                                       | Management interface, not an HA replacement. Restrict to operator network/VPN; keep GitLab/Git as deployment authority. Check selected edition/version permissions before relying on read-only access. |

Three small VMs can be a Swarm learning baseline, not a proven resource sizing or complete HA guarantee. They must occupy distinct physical failure domains where possible. Running a complete self-managed HA database and storage layer alongside the app can exceed that baseline substantially. Kubernetes can run Docker-built OCI images; it does not require Docker Engine as its node runtime.

Do not install both Swarm and Kubernetes for the demo. Do not expose Portainer, cluster APIs, database ports, or Docker sockets to the public application. GitLab builds immutable artifacts; approved deployment jobs update declarative configuration. Portainer changes made during emergencies must be reconciled back into Git.

Sources: [Portainer environments](https://docs.portainer.io/admin/environments), [Swarm quorum](https://docs.docker.com/engine/swarm/admin_guide/), [Kubernetes production considerations](https://kubernetes.io/docs/setup/production-environment/).

## Deployment capacity principles

Environment-specific host names, node counts, allocations and placement details are intentionally omitted. Size the deployment using measured demand, failure scenarios and recovery requirements.

Spread replicas across independent failure domains and retain capacity for host loss and rolling updates. Protect orchestration resources with reservations and limits. Database and media redundancy require explicit durability and failover designs; orchestration alone is insufficient.

Use persistent storage, bounded logs, disk alerts and upload quotas. Raw allocated capacity is not usable media capacity: account for replication, system overhead and recovery headroom. Keep tested backups outside the deployment failure domain. Run builds separately from application workloads.

## Scalability after correctness and failover

Start with two Laravel application replicas and redundant ingress spread across hosts; React assets belong to the Laravel/Inertia release. Keep requests independent of a particular replica: no sticky-session requirement, no local authoritative uploads, and shared session state. Give each replica bounded database connections so adding replicas cannot exhaust PostgreSQL.

Measure concurrent users, feed latency, upload processing time, error rate, memory, and database connections with a realistic test dataset. Agree on a modest load-test target once expected users are known; do not advertise an unmeasured user capacity. Increase replicas only after identifying the bottleneck. Thumbnail generation and bounded image sizes are likely more valuable early than autoscaling.

Use cursor pagination and proper indexes before adding feed caches or database read replicas. Limit upload concurrency and account storage usage. If image processing later becomes a bottleneck, add a bounded background worker queue with retries and idempotency; this is optional, not a v1 prerequisite. Kubernetes autoscaling requires metrics and spare node capacity; Swarm can scale services explicitly without implying built-in metric-driven autoscaling.

## A small, polished interface

Recommended first release: **Feed, Create, Profile**. Keep Settings behind the avatar menu and operator functions outside ordinary navigation.

- **Feed:** generous image cards, avatar/name, short caption, like button, and a subtle overflow menu for delete/report. Start with one chronological community feed. Put comments and follow/following behind a later milestone; these are proposed cuts from the original feature list.
- **Create:** choose one photo, see a preview, add a caption, publish. Clear upload progress and useful errors. No filters, video, stories, or multi-step editor.
- **Profile:** avatar, name, compact photo grid, sign-out/settings. Avoid follower counters until following exists.
- **Signup/login:** personal email/password, SWITCH, company Microsoft and Google; GitHub tentative. Enable only configured providers, apply the same admission policy to each, and defer passkeys. No automatic email-based linking.

Visual direction: warm neutral background, white/slightly tinted cards, charcoal text, one restrained accent colour, rounded corners, consistent spacing, crisp typography, and photography as the focus. Use a narrow readable feed on desktop and bottom navigation on mobile. Prefer accessible tested UI primitives with a small reusable set of Button, Input, Dialog, Card, and Toast components.

Quality includes keyboard navigation, visible focus, adequate contrast, large touch targets, useful empty states, loading placeholders, reduced-motion support, and clear errors. Avoid decorative motion that interferes with uploading or browsing. Produce a responsive mockup before implementing screens; no visual mockup is part of this document update.

## Demonstrate the priorities

1. Security: prove cross-user access denial, private media, invalid OIDC rejection, logout/disablement, and upload bounds. No unsafe linking or admission-policy bypass; open signup requires verification.
2. HA: kill an app process, then lose an entire host, then exercise database failover. Verify external requests, private photos, and existing valid sessions; prove a revoked session stays revoked across failover.
3. Dependency failures: lose Redis, Graph, and storage independently. Show documented degraded behavior with no authorization bypass or false upload success.
4. Recovery: restore database and media into a clean environment; test recovery of a previous compatible release. Backup is separate from replication.
5. Scale: increase app replicas under load and record before/after throughput, latency, errors, and connection counts.

Suggested initial host-failure target: service recovers within 60 seconds under the agreed test load; no loss of acknowledged database changes under the defined single-host failure. These are design targets, not achieved results. Define separate media durability, session behavior, and disaster-recovery targets with the chosen provider services.

Environment-specific deployment details are omitted. Open inputs: actual provisioning and failure domains, operated HA database/object storage/load balancer, expected users, exact school deadline (approximately end of November), provider registrations and SWITCH onboarding. Swarm is the proposed platform; complete HA topology and sizing remain conditional on these inputs.
