# Application architecture

Momento uses Laravel with Inertia and React. Laravel owns routing, authentication, validation and authorization; browser assets ship with the application. No separate browser bearer-token API or production Node service is required.

## Application contracts

- PostgreSQL stores application records and authoritative sessions. Redis provides shared cache and rate limits; it does not decide durable authorization.
- Open local signup requires email verification. Optional local two-factor authentication and passkeys share the local account and session lifecycle.
- External identities use validated provider/issuer/subject identifiers. Never link accounts or add local credentials merely because email addresses match.
- Profiles expose only the allowed identity fields. Photos and captions are restricted to the owner and approved followers, with authorization required on every read.
- Uploaded media uses bounded decoding, orientation handling, metadata removal, private storage and verified cleanup. Publishing requires an explicitly configured, qualified isolated decoder; codec availability alone does not enable it.

## Runtime

The [Docker runtime](runtime.md) serves Laravel and built browser assets using PHP 8.5 and FrankenPHP classic mode. Configuration and durable state are external to the image. Liveness and dependency readiness are separate.

## Current implementation boundary

Follow requests support owner approval, decline, cancellation, unfollow and follower removal. Each new request after removal requires fresh approval; photo-access checks query the current relationship. Member links and people search expose display names and route identity to verified members.

Photo search matches literal caption text case-insensitively using the database's case rules. It returns twelve authorized photos per cursor page, newest first, with no global totals or location/embedded-metadata search. Search responses send `private, no-store` cache headers; pagination and copied media/detail links recheck access.

The authorized feed, profile grids, photo detail and private media responses share the current-access rule. Likes expose only the viewer's state; saves are private and disappear from results when access is lost. Plain-text comments support bounded pagination and removal by their author or post owner. Owner deletion hides a post immediately; storage remains charged until cleanup is verified.

Text chat requires mutual approved follows. Unfollowing stops new sends while preserving each participant's history. Attachments, read receipts and message requests are not implemented. Account settings retain read-only email and disable whole-account deletion; product-news consent is separate from authentication mail.

Shared React layouts use bundled EB Garamond and DM Sans, semantic light/dark colors and existing Radix/shadcn primitives. Navigation expansion and decorative motion honor reduced-motion preferences; the corner animation can also be paused.

External-provider, live mail, qualified publishing and deployment acceptance remain separate from source and runtime checks.

Use the [authentication plan](authentication-plan.md) for identity policy and [verification record](workflow-assessment.md) for scoped evidence. Actual deployment infrastructure, network and storage arrangements are confidential and are documented outside this repository. Do not infer a hosted topology or availability guarantee from application code.
