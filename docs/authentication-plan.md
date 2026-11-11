# Authentication

Momento supports verified email/password accounts with optional authenticator-app codes, recovery codes and passkeys.

Google and institutional SWITCH sign-in use OpenID Connect when configured. Each requires separate verification of a contact email before account creation. Provider email addresses never link or merge accounts. Microsoft approved-tenant sign-in remains a separate pending integration.

## External identities

- Accounts use the validated provider, issuer and case-sensitive subject as their external identity.
- Authorization requests use state, nonce and S256 PKCE. Server-side verification checks signatures, issuer, audience, expiry and transaction binding.
- SWITCH also requires a signed institutional-domain claim that matches the current institution allowlist. Personal edu-ID identities do not meet this policy.
- Provider-only accounts cannot add local passwords, recover through local password reset or enroll local factors to bypass provider sign-in.
- Missing configuration disables the provider. Configuration availability does not establish provider readiness.

Provider tokens stay on the server during verification and are not retained or sent to the interface. Successful sign-in rotates the application session; disabled accounts and disallowed identities lose access.

## Local credentials

Local signup requires email verification. Recovery responses do not reveal whether an account exists. An enrolled authenticator requires a current code or an unused recovery code after password sign-in; password reset preserves the factor. A passkey requires user verification and remains an alternative local sign-in method.

Credential management requires recent authentication. Recovery-code regeneration invalidates the old set, and removal of the last passkey preserves password-and-factor recovery. There is no automated lost-all-factors bypass.

## Verification boundary

Automated coverage exercises signed provider tokens, wrong claims and signatures, callback replay, contact-proof expiry and replay, email and identity collisions, disabled accounts and local-credential guards. PostgreSQL checks exercise concurrent account creation.

Real Google and institutional SWITCH registration, released claims, hosted callbacks and mail delivery remain pending acceptance. Microsoft provider acceptance is also pending. No deployment or live-provider acceptance is implied by local tests.
