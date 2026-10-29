# Container delivery

Pull requests run source and container checks. Trusted branch pushes publish the tested image to private GHCR.

Publication requires existing package metadata with HTTP 200 and private visibility. Missing, inaccessible or non-private metadata stops the push. A fresh verifier checks private visibility, anonymous denial, read-only digest pull and source labels.

Use the verified digest from the CI summary for deployment. Tags are not immutable. Keep runtime configuration and credentials outside images.

Failures leave CI red. Fix the cause before retrying; a rebuild may produce a different digest. Image publication does not establish live deployment or recovery.
