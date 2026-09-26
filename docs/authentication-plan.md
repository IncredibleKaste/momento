# Authentication scope and implementation plan

Updated 2026-09-26 following user confirmation. Requirements/design only; no provider is implemented or live-verified. This supersedes the earlier local-plus-single-tenant-Microsoft-only scope. Laravel owns authentication routes and sessions; React through Inertia supplies signup/login UI.

## Requested options

| Method                      | Scope                                                     | Integration and outstanding input                                                                                                                            |
| --------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Personal email and password | Required signup/login option                              | Laravel-native authentication, verification, reset, throttling; open signup with verification confirmed; mail delivery pending                               |
| SWITCH AAI                  | Required option                                           | Institutional SWITCHaai confirmed; no service registration exists yet. Federation onboarding, protocol/deployment and released attributes require validation |
| Microsoft company login     | Required option; one test Entra tenant available per user | Backend OIDC integration; multiple approved company tenants confirmed; maintain an explicit tenant allowlist; the test tenant is one test resource           |
| Google account              | Required option                                           | Backend provider integration; app registration, redirect configuration and minimal scopes required                                                           |
| GitHub account              | Tentative                                                 | Optional later provider; do not make it a first-release prerequisite without confirmation                                                                    |
| Passkeys                    | Later                                                     | WebAuthn credential registration/authentication, recovery and removal require a separate feature design; no current implementation                           |

The first-release sequencing is proposed, not a reduction of requested scope: local signup/login and sessions → allowlisted multi-tenant Microsoft integration (using the test tenant first for evidence) and Google → SWITCH integration in parallel with external registration readiness → optional GitHub → passkeys later. Raise SWITCH registration early so external onboarding does not become a last-minute blocker. Exact deadline is around end of November; release expectations for each provider need mapping to the school rubric.

## Confirmed admission and provider scope

- Open signup with verification, confirmed by the user. No invitation or manual approval requirement for ordinary community membership. Define verification explicitly: local email requires a one-use expiring verification link; external-provider verification and claim semantics must be checked per provider. Proposed default where no trustworthy verified-email claim is available: collect and verify a contact address before granting community access. Do not universally treat a returned email string as verified.
- Microsoft supports multiple approved company tenants. Use an explicit configured tenant-ID allowlist and reject unapproved tenants even when their tokens are otherwise valid. Personal Microsoft accounts are not requested. Obtain actual allowed tenant IDs and provider registration/consent before live use; never invent them. The user's test tenant is available, but its configuration and credentials have not been inspected.
- Institutional SWITCHaai is requested, not merely direct edu-ID. No service registration exists. Confirm eligible service operator/sponsor and start federation/test registration; agree on integration path, metadata, callback/entity identifier and attribute release with the operator. Do not substitute a direct edu-ID button for the requested institutional integration.

“Private community” now means authenticated, verified membership, not an invitation-only or company-only audience. Any verified person can join through an allowed ordinary signup method. Microsoft tenant approval governs that provider route; it cannot restrict overall community membership while personal email and Google signup remain open. If company-only resources or roles are introduced, enforce their eligibility separately; local/Google signup must not impersonate company affiliation or grant those privileges.

Open signup raises abuse needs: bounded registration and verification-mail rates, generic recovery responses, verified-access gates, per-account upload quotas, moderation/disablement and a review of retention/privacy disclosures. No legal compliance claim is made by this plan.

Provider setup and SWITCH federation onboarding are implementation dependencies, not reasons to reopen the confirmed stack or stop independent local development.

## Account and session model

Retain one internal user identifier independent of email or login provider. Proposed logical records: users/membership status, optional local password credentials, external identities keyed by provider plus validated issuer/tenant and stable subject, and authoritative PostgreSQL sessions. Enforce identity uniqueness transactionally; never use a mutable/display email as the provider identity key.

Existing decision retained: no automatic merging or linking when emails match. Multiple login buttons do not imply that a Google, Microsoft and local login belong to one person. Without an explicit verified linking flow, they remain separate identities/accounts, subject to verified open-signup membership checks. Explain this to users. If a unified account with multiple methods is desired, design explicit linking after fresh proof of both identities before enabling it; no silent password addition to federated accounts.

Keep privileged or tenant-policy-required identities from acquiring a weaker alternate sign-in method. Password reset only applies to local credentials; an email-based recovery flow must not take over a federated-only account. Passkeys later must bind to an authenticated internal user and must not be treated as evidence that unrelated provider accounts belong together. Do not imply a local passkey automatically satisfies company federation policy.

All successful methods establish the same application session and are subject to membership, disablement and authorization checks. Apply session rotation, bounded lifetime, logout/revocation and CSRF protection. Do not persist provider tokens unnecessarily, request broad Graph/repository scopes, or send tokens to React. Avatar retrieval is optional, not a prerequisite for login; no Graph scope is approved by this plan.

## Provider-specific trust boundaries

- Microsoft and Google: browser redirects/callbacks are untrusted inputs; validate the chosen protocol through a maintained library, including transaction binding, exact callback, issuer/audience/expiry and nonce where applicable. Enforce tenant policy for Microsoft. Keep authorization-code exchange and secrets on Laravel.
- SWITCH: SAML and OIDC are distinct paths. For SAML, require trusted metadata/signatures, audience/recipient/destination, freshness, response correlation and replay prevention; agree on a stable released identifier. If an external SP/proxy terminates SAML, the authenticated-identity handoff is a new trust boundary: strip spoofed headers and prevent direct bypass. No proxy/header design is yet selected.
- GitHub if adopted: validate the OAuth transaction, retrieve identity through the authenticated provider API and use its stable user ID. Do not assume every OAuth provider returns an OIDC ID token or a public/verified email.
- Email delivery: backend → mail service carries verification/reset links and recipient data. Select a provider, scope credentials, protect logs, bound token lifetime, consume tokens safely and test unavailable/delayed delivery without bypassing verification.
- Passkeys later: review RP ID/origin, one-use challenges, user verification, credential ownership, recovery/removal, browser support and interaction with organization-required login. No private authenticator key belongs in the app database.

## Acceptance evidence

In addition to existing session/authorization tests: forged, expired, replayed or wrong-provider callback rejected; wrong Microsoft tenant rejected; same email never auto-merges; concurrent callbacks cannot duplicate identity ownership; unverified/disabled users denied community access through every method; unapproved Microsoft tenants denied on that provider route; missing email/claims handled without invented identity; login CSRF rejected; external outage offers no bypass. Verify email/reset token expiry and replay handling, verification-mail throttling, unverified-user media denial and that alternate signup cannot confer company-specific privileges. Real test-provider browser login is separate from mocked integration tests.

SWITCH requires real test-federation/service validation of metadata and released attributes. Passkey tests and browser/hardware evidence are deferred with that feature. Session revocation must remain effective after database failover regardless of login method.

## Tooling and sources

Recommendation: start from Laravel's official React/Inertia starter kit for the application and local auth. Evaluate Socialite for Google and optional GitHub; Microsoft needs a separately evaluated adapter/OIDC integration, and SWITCH needs its own protocol/onboarding choice. Do not assume Socialite alone supports all requested protocols or selects the right claims/policy. No package has been installed or chosen irrevocably.

Primary sources checked 2026-09-26:

- [Laravel starter kits](https://laravel.com/docs/13.x/starter-kits): React/Inertia integration and Laravel authentication foundation.
- [Laravel Socialite](https://laravel.com/docs/13.x/socialite): documented Google/GitHub provider support; additional providers need separate integration review.
- [Switch edu-ID](https://www.switch.ch/en/edu-id): SAML and OpenID Connect integration options.
- [SWITCH federation guides](https://help.switch.ch/aai/guides/): service-provider and test-federation setup.
- [Microsoft single/multitenant applications](https://learn.microsoft.com/en-us/entra/identity-platform/single-and-multi-tenant-apps): account audience choices and tenant scope.

These sources describe integration options, not successful Momento configuration or provider approval.
