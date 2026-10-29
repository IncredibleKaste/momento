# Verification

CI records the following checks and their results for each commit.

- `composer ci:check`: PHP tests/static analysis/formatting and frontend lint, format and types.
- `npm run build`: browser assets and generated Wayfinder routes.
- Container CI: Linux image build, Caddy validation, unprivileged code/storage permissions and selected image exclusions.
- Container CI: JPEG/PNG/WebP/HEIC codec round trips in the actual image.
- Container CI: disposable PostgreSQL/Redis services, migrations, Artisan route listing and login rendering.
- Container CI: application recreation preserves external test data; dependency outages return readiness 503 while liveness stays available, then recover.

Artifact verification checks private visibility, anonymous denial, read-only digest pull and source labels. CI summaries identify the tested commit and image digest. See [delivery](delivery.md).

Container checks do not establish live deployment, provider authentication or operational recovery.
