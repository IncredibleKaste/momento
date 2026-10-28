# Product delivery boundaries

Deliver one feature branch at a time. Define its observable success/failure behavior, implement the complete bounded change, run relevant checks and review it before merging its PR into develop. Begin the next feature after integration. Promotion to main is a separate release action.

The selected application core includes verified local authentication, optional local two-factor authentication/passkeys, an approved-tenant Microsoft provider, basic profiles, single-photo posts, directional approved-follow relationships, an authorized chronological feed and owner deletion. SWITCH is optional; broader social and messaging functions remain later scope.

Keep implementation evidence separate from deployment or live-provider acceptance. [Architecture](architecture.md), [authentication](authentication-plan.md) and [runtime](runtime.md) describe public application contracts. Internal milestones, operational plans and all real infrastructure information stay outside this repository.
