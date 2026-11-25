# WebAuthn dependency security verification

QA-only update: `web-auth/webauthn-lib` is locked to patched version `5.3.17`.
The previous `5.3.12` lockfile failed `composer audit` with six advisories.

The dependency update was resolved by Composer and passed its audit before
the lockfile was committed. Full backend tests and Pint must pass in isolated
SQLite memory before Staging deployment; no production dependency was changed.
