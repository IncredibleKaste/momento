# Git and private image delivery

Use a short feature branch and a reviewed pull request into develop. Source and container checks must pass before integration. Promotion to main is a separate reviewed release action. Configure branch protection to require the current source and container checks; author review is distinct from independent approval.

## Pipeline boundaries

Pull requests run with read-only repository authority and cannot publish an image. The container workflow is reused so the tested runtime suite has one implementation. No image tar archive is uploaded to public workflow artifacts.

A trusted push to develop or main first passes source checks, then builds and exercises the complete container suite. Only that tested image is published. The publishing job receives a short-lived repository GITHUB_TOKEN with packages:write; no personal publishing token or deployment secret is required. The package must already exist and its metadata must confirm private visibility before any application image is pushed. Missing, inaccessible or non-private packages stop publication; package creation and its visibility must be verified separately. Do not rely on default visibility. The following job verifies visibility again.

A separate fresh job has packages:read only. It proves anonymous access is denied, pulls the canonical image digest and checks the source repository/revision labels. A plain unauthenticated registry 401 is not sufficient proof: public images can also require an anonymous bearer token. The check follows that token path before deciding access is denied.

Repository writers and maintainers remain trusted authorities. Fork/untrusted PRs must never receive publication or deployment credentials. Do not introduce pull_request_target, a privileged workflow_run consuming PR artifacts, or inherited deployment secrets into these jobs. Protect workflow changes through review.

## Artifact identity and retries

The final verification job's summary records the source commit and image reference ending in `@sha256:...`. Deploy that digest. Tags include source commit, workflow run and attempt for discovery; tags are not claimed immutable. OCI labels are traceability metadata, not a cryptographic signature.

A failed source check prevents publication. A failed build, runtime check or privacy preflight prevents the image push. A failed publication or read verification leaves CI red and does not deploy anything. Fix the cause and rerun the affected workflow. A full rerun creates a new attempt tag; do not assume a rebuild has identical bytes, because base packages may have changed. Retain and review the recorded digest you intend to use.

## Operator handoff

Use only a successful final verification summary. Confirm its source commit and intended branch, then copy the complete digest reference into controlled deployment configuration outside this repository.

An external deployment machine needs a credential restricted to read:packages and the intended private package. Provision it outside chat and Git; confirm package access and revoke it when no longer needed. The fresh CI read-only pull proves the registry artifact, not the deployment machine's network/credential setup.

A generic local pull looks like this; values below are placeholders:

```sh
export IMAGE='ghcr.io/owner/application@sha256:replace-with-verified-digest'
printf '%s' "$REGISTRY_READ_TOKEN" | docker login ghcr.io --username "$REGISTRY_USER" --password-stdin
docker pull "$IMAGE"
docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$IMAGE"
docker logout ghcr.io
```

Keep actual infrastructure names, addresses, networks, sizing, storage locations, credentials and operator procedures outside this repository under the [confidentiality rule](../.ai/rules/general.md). Image publication is not live deployment, provider authentication or operational acceptance.

Primary references: [GitHub Container registry](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry), [reusable workflows](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows), and [registry authentication](https://docs.docker.com/reference/api/registry/auth/).
