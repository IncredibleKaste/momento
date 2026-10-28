# Runtime verification record

E01 adds a non-root HTTP image, built frontend assets, external configuration/state, generic dependency readiness and codec capability. This record contains application evidence only; deployment details and internal planning remain outside the repository.

## Required checks

- `composer ci:check`: PHP tests/static analysis/formatting and frontend lint, format and types.
- `npm run build`: browser assets and generated Wayfinder routes.
- Container CI: Linux image build, Caddy validation, unprivileged code/storage permissions and selected image exclusions.
- Container CI: JPEG/PNG/WebP/HEIC codec round trips in the actual image.
- Container CI: disposable PostgreSQL/Redis services, migrations, Artisan route listing and login rendering.
- Container CI: application recreation preserves external test data; dependency outages return readiness 503 while liveness stays available, then recover.

Native source checks passed with 47 tests and 160 assertions. The implementation also passed source and container CI before the confidentiality documentation cleanup; the updated branch must pass its current checks before integration. GitHub PR checks identify the current source revision and result. Test service names, addresses, passwords and media canaries are disposable examples, not deployment configuration.

## Ponytail author review

Review followed route/middleware behavior, application-key/schema/dependency failures, connection timeouts, private-file paths, code/storage permissions and build boundaries. Two concrete packaging issues were corrected: preserve composer.json for Laravel namespace discovery and include HEIC codec delegates. Both have container checks. There is no open must-fix finding within this runtime unit. This is author review, not an independent assessment.

The runtime does not prove full photo processing, product authorization, provider/browser flows or operational acceptance. See [runtime instructions](runtime.md), [architecture](architecture.md) and [authentication policy](authentication-plan.md).

## E02 artifact delivery candidate

Source/container checks are reused for read-only PR validation and trusted-branch publication. The publisher pushes the exact image that passed its container suite. A separate fresh job verifies private visibility, anonymous denial, read-only digest pull and source labels. CI summaries provide the source/digest handoff; no image archive or actual deployment configuration is published.

Current hosted checks, registry access and branch protection must be verified for the implementation candidate before claiming E02 complete. See [delivery boundaries](delivery.md).
