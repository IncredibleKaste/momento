# Momento workflow assessment

Assessment date: 2026-09-26; revised after the Laravel correction. Applied skill: `software-project-workflow`.
This is an engineering assessment, not an organizational classification, security certification, or production-readiness claim.

**Current architecture:** [Laravel decision](architecture.md). Earlier application architecture plans are superseded. Laravel + Inertia + React, PostgreSQL, Redis and multi-host Swarm are confirmed. Expanded authentication scope is recorded in [authentication-plan.md](authentication-plan.md); open verified signup and multiple approved Microsoft tenants are confirmed; institutional SWITCHaai registration and provider configuration remain pending.

## Evidence and preservation

At assessment start, Git tracked only README.md. Two untracked planning documents existed; no application, tests, dependency manifests, containers, or CI configuration existed. Preserve those documents and the chosen architecture. There is no working application to rewrite.

The README's claim that `momento.md` was excluded from Git was false in this checkout: `git check-ignore` did not match it and Git listed it as untracked. Added a repository `.gitignore`. The transcript was not opened or modified during this assessment. Its documented credential exposure still requires revocation/replacement before identity integration; ignoring it does not revoke credentials or protect manual uploads.

User-confirmed: deadline approximately end of November 2026; deployment provisioning is not verified. Exact deadline, rubric, service availability, physical placement, and operator ownership remain unknown.

## Classification

**Assurance: STANDARD.** This is intended to become maintained school-project software with real accounts and private content. It is neither a disposable experiment nor demonstrated PRODUCTION software.

**Governing risk: R2 HIGH, provisional for the intended application.** There is currently no application implementation or verified Laravel runtime. Do not confuse planned capabilities with implemented controls. The historical credential is a separate existing secret-handling concern, with scope and revocation status unknown.

| Dimension       | Intended app                              | Rationale / unknown                                                                                                                                                          |
| --------------- | ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Confidentiality | R1–R2, use R2 controls provisionally      | Private photos, account data, sessions; audience, sensitivity and retention not agreed                                                                                       |
| Integrity       | R1                                        | App-owned posts and profiles; no authority over institutional records planned                                                                                                |
| Availability    | R1                                        | School community outage; HA is a design objective, not proof of critical impact                                                                                              |
| Exposure        | R2 provisional                            | Browser/API access and untrusted images with meaningful private state; open verified signup confirmed; actual network reachability still unresolved                          |
| Privilege       | R1 app; R2–R3 deployment boundary unknown | Ordinary app CRUD; Swarm administration credentials would introduce control-plane authority                                                                                  |
| Automation      | R1 app; R2 planned delivery               | Bounded image processing; future unattended deployment can change a shared environment                                                                                       |
| Blast radius    | R1 app                                    | R1 initially assumed for a small school community; open signup may increase user count, so reassess actual scale. CI/host authority must remain isolated from other projects |
| Recoverability  | R2 provisional                            | Database and photos require coordinated restoration; no restore evidence                                                                                                     |

R2 is driven by private/untrusted content and recovery needs, not by averaging scores or the word HA. Model CI/Swarm administration separately: if deployment receives root/control-plane authority, assess that component as R3 where material and obtain independent owner/security review before use. Do not give the application a Docker socket or deployment credential. Residual risk cannot yet be rated as controlled: nearly all proposed safeguards are unimplemented.

Reassess at real-user onboarding, upload processing, Entra scopes, public exposure, deployment credentials, data sensitivity, and automated deployment.

## Capabilities and boundaries

Implemented now: no runtime application capabilities. `INBOUND_API` remains planned. Existing concern: `SECRETS` in the historical transcript, based on the existing project's warning, without inspecting its value.

First functional slice: `AUTH`, `SESSION`, `AUTHZ`, `DATABASE`, `FILE_UPLOAD`, `FILE_PROCESSING`, `UNTRUSTED_CONTENT`, `SECRETS`, `INBOUND_API`.

Later planned: `ADMIN` for moderation/disablement; `EXTERNAL_IDENTITY`, `EXTERNAL_API`, `OUTBOUND_NETWORK` for SWITCH, Microsoft, Google, optional GitHub and optional avatars; `SHARED_STORAGE` for shared private photos; `AUTOMATION`, `EXTERNAL_MUTATION`, `SECRETS` for CI deployment. `PRIVILEGED_SYSTEM_ACCESS` applies to deployment if its actual credentials administer hosts/Swarm; it is not an application requirement.

The following are planned relationships. PostgreSQL, Redis and the multi-host Swarm target are confirmed choices; deployment is still unverified. The authentication plan extends the external-identity boundaries below to SWITCH, Google, optional GitHub and mail delivery. Source/target environments are local development first, then a separate demo environment; never share credentials/data between them. Owner of application: project team; infrastructure and tenant owners: unconfirmed.

| Boundary / direction relative to app                            | Authority and capabilities                                                                     | Data / exposure / trust                                                                                  | Required behavior and evidence                                                                                                                  |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Browser → app, inbound                                          | READ/CREATE/DELETE own content; AUTH/SESSION/AUTHZ/INBOUND_API/FILE_UPLOAD/UNTRUSTED_CONTENT   | Credentials and private photos; LOCAL initially, INTERNET or ORGANIZATION_INTERNAL later; UNTRUSTED      | Validate inputs, cookie/CSRF policy, resource limits, membership and ownership checks; two-user denial tests                                    |
| App → PostgreSQL, internal                                      | READ/CREATE/UPDATE/DELETE app data; DATABASE/SESSION/SECRETS                                   | Password hashes, sessions, posts; APPLICATION_INTERNAL; CONTROLLED                                       | Restricted DB role, migrations, uniqueness/transactions, durable revocation; real PostgreSQL integration tests                                  |
| App → photo storage, internal or outbound depending on provider | READ/CREATE/DELETE app objects; FILE_PROCESSING/SHARED_STORAGE                                 | Private photos; APPLICATION_INTERNAL or provider endpoint; CONTROLLED or ORGANIZATIONAL pending provider | Bounded decode/re-encode, generated keys, private serving, cleanup after partial writes; malformed image and authorization tests                |
| App → Redis, internal                                           | READ/CREATE/UPDATE expiring rate-limit state                                                   | Counters, not authoritative sessions; APPLICATION_INTERNAL; CONTROLLED                                   | Sensitive operations fail closed on limiter outage; fault test                                                                                  |
| App ↔ Entra/Graph, outbound plus inbound callback               | Authenticate and READ own avatar; EXTERNAL_IDENTITY/EXTERNAL_API/OUTBOUND_NETWORK/SECRETS      | Tokens/identity/avatar; INTERNET; THIRD_PARTY with tenant governance                                     | Exact issuer/tenant validation, stable identity, no email linking, bounded avatar response; actual tenant login remains required                |
| CI → registry/Swarm, outside runtime app boundary               | CREATE artifacts; UPDATE deployment, possibly ADMINISTER; AUTOMATION/EXTERNAL_MUTATION/SECRETS | Images and deployment credentials; ORGANIZATION_INTERNAL assumed, verify; ORGANIZATIONAL                 | Protected credentials, least authority, immutable artifact, serialized migration/deploy, stop/recovery path; pipeline and live-release evidence |
| Backup process → external backup storage, outbound              | CREATE backup and READ for restore                                                             | DB/media including security state; exposure/provider UNKNOWN                                             | Separate failure domain, access/retention/encryption policy, coordinated restore; clean-environment drill                                       |

High-impact failure paths: stolen session, cross-user deletion/media access, malicious image exhausting resources, stale revocation after failover, orphaned media after DB failure, and compromised CI reaching unrelated infrastructure. Each requires a negative/failure test before the relevant capability is considered complete.

## Workflow gaps and order

| Area                             | Assessment                                                       | Next action / completion evidence                                                                                                    |
| -------------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Purpose and scope                | SATISFIED for starting; rubric missing                           | Small private photo community and container-delivery learning; obtain assessment criteria                                            |
| Classification and relationships | Added by this assessment                                         | Revisit listed triggers; owner review for privileged deployment                                                                      |
| Architecture                     | Confirmed Laravel + Inertia + React / PostgreSQL / Redis / Swarm | Current architecture established; implement verified signup and tenant allowlisting; resolve SWITCH registration/configuration       |
| Repository confidentiality       | Immediate gap repaired                                           | Git excludes transcript and local env files; credential revocation remains unverified                                                |
| Reproducible development         | Missing after removal of obsolete scaffolding                    | Select Laravel/PHP tooling after confirmation, lock dependencies, verify clean install and checks                                    |
| Capability controls              | MISSING EVIDENCE                                                 | Implement/test auth, private media, bounded processing and lifecycle; do not infer safety from plans                                 |
| CI / supply chain                | Missing                                                          | Add baseline validation after local commands work; runner/executor unknown; scans and image pinning still pending                    |
| Release and operations           | Missing, not needed to run local health probe                    | Ownership, data retention, backup/restore, rollback, monitoring and rotation before real reliance                                    |
| HA and capacity                  | Design only                                                      | Confirm ingress/storage/database services; measured host loss and revocation-preserving failover                                     |
| Learning                         | Backlog                                                          | Explain sessions versus identity, ownership checks, DB/object partial writes, and replication versus backup alongside implementation |

## Release scope and next increment

Product scope: personal-email signup/login plus SWITCH, company Microsoft and Google; GitHub tentative and passkeys later. Open signup with verification and multiple approved Microsoft tenants are confirmed; first-release provider sequencing remains proposed. Retained application scope: one validated photo and caption per post, private chronological feed, own-post deletion, minimal profiles/likes, operator disablement, responsive usable UI. The earlier two-provider-only scope is superseded. No automatic linking; explicit linking remains deferred. Open verified signup supersedes the earlier invitation-only proposal. Comments, follows, chat, video, and stories remain deferred. HA remains a project objective with a separate evidence milestone, not a claim attached to the first local release.

Current increment: confirmed stack and expanded authentication design. Obsolete application scaffolding has been removed. Preserve the requirement to distinguish process liveness from readiness; exact Laravel routes and implementation are pending. No foundation milestone is currently claimed complete.

After Laravel foundation: PostgreSQL schema/migrations and local signup/login/logout under the confirmed admission policy, before uploads. Decide verification-mail delivery, recovery and session lifetimes explicitly. Use authoritative PostgreSQL sessions; Redis is only a limiter/cache. Acceptance: clean-schema migration works, invalid/disabled users are denied, session fixation is prevented, logout invalidates access, CSRF requests are rejected, and limiter failure cannot permit unlimited login attempts. Test against PostgreSQL, not SQLite as a substitute.

Then add upload → private feed → own deletion with two users, byte/pixel/type limits, metadata removal, authorization on every media read, and cleanup after storage/DB failure. Browser verification is separate from API test success. UI design accompanies that slice; a standalone mockup does not prove the security boundary.

Feature done means acceptance and negative cases pass, checks pass, docs reflect behavior, and remaining limitations are explicit. Release done additionally needs clean reproducible setup, dependency/secret checks, and the relevant integration evidence. PRODUCTION promotion requires a separate readiness review; it is not authorized by an end-of-course demo.

## Sources

Skill sources: engineering invariants, assurance profiles, risk model, capability registry, relationship model, project lifecycle, and feature workflow read on 2026-09-26. The R0–R3 labels are the skill's internal engineering model.

Security references: [OWASP session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) and [OWASP file uploads](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html). These describe requirements, not evidence of implementation.

## Verification of the architecture correction

Inspected recent source, tests, manifests and documentation before removing the framework-specific artifacts. Preserved risk/capability analysis and security acceptance expectations. Documentation and Git exclusions checked; no Laravel runtime, dependency installation, application tests or deployment attempted. See architecture.md for the artifact-by-artifact disposition.

## Authentication capability delta

The user expanded authentication from local/Microsoft to local, SWITCH, Microsoft company accounts and Google, with GitHub tentative and passkeys later. STANDARD / provisional R2 remains appropriate; no new institutional administration authority is requested. More providers increase external trust boundaries, account-collision/recovery paths and onboarding dependencies. Confirmed open verified signup strengthens abuse/exposure requirements. Implement the confirmed approved-tenant policy and verify SWITCH claim/affiliation trust before integration. Passkeys are a later AUTH/credential-lifecycle change, not permission to bypass required federation policy. All runtime capabilities remain unimplemented.

Confirmed authentication inputs: open verified signup; multiple approved Microsoft company tenants; institutional SWITCHaai with no existing service registration. No identities or tenants have been configured. Ordinary community privacy excludes anonymous access, not verified outsiders; company-specific privileges require separate eligibility checks. Provider callbacks and verification semantics still need implementation and real-provider evidence.
