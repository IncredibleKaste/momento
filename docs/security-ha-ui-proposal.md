# Application security and UI requirements

## Identity and authorization

Use Laravel browser sessions and CSRF protection. Rotate sessions on authentication and revoke them on logout. Require verification before protected community actions. Match external identities by validated issuer/subject and tenant policy; matching email addresses never authorize linking or local-password addition.

Review optional local two-factor and passkey login, fallback, recovery and removal together. Provider-only identities retain provider authentication policy. Check token freshness, replay/transaction binding, credential ownership and expected origins. Do not request or retain unnecessary provider tokens or scopes.

Photo visibility is directional and approval-based. Every post, feed, profile, thumbnail and direct-media read must apply the same owner/approved-follower rule. Removing a follower or deleting a post must deny new reads immediately.

## Media and failure behavior

Validate file content and bounded dimensions before expensive processing. Correct orientation, remove private metadata, generate storage keys and keep media outside the public web root. Enforce account quotas and clean up partial or deleted files safely. Test malformed images, rejected writes and authorization changes during a read.

Liveness and dependency readiness have distinct purposes. Test dependency failure without adding an authentication or authorization bypass. Application replication alone is not proof of availability or recoverability; actual infrastructure designs and measurements remain confidential outside the repository.

## Interface

Use the selected visual prototype as a reference for coherent React components. Preserve readable contrast, keyboard navigation, visible focus, accessible forms and reduced-motion behavior. Verify delivered screens at supported viewport sizes. A static prototype is not evidence of server-side privacy, persistence or provider authentication.

[Architecture](architecture.md), [authentication](authentication-plan.md) and [runtime verification](workflow-assessment.md) own their current public contracts and evidence.
