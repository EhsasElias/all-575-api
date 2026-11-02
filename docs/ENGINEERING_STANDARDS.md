# ALL-5.75 Engineering Standards

Applies to every future backend feature and refactor. These standards are guidelines, not a claim that all existing code already complies.

## Architecture
- Keep controllers thin: authorization, request validation, service invocation and response formatting.
- Place business rules in named domain actions/services; use one clear responsibility per class.
- Keep database access scoped to the authenticated user, with explicit authorization for each operation.
- Put notification creation behind a service; do not couple checkout success to optional side effects.
- Do not expose internal exception text, secrets, credentials, or private customer data in API responses.

## Reliability and scalability
- Apply pagination and maximum limits to list endpoints. Index common filtering columns.
- Keep checkout/payment operations idempotent and never duplicate business actions on retries.
- Use queue jobs and the outbox pattern for reliable future notification delivery once worker infrastructure exists.
- Use schema migrations for database changes; plan backups and rollback before production deployment.
- Avoid N+1 database queries and keep transactions short.

## API quality
- Use consistent response structures, status codes and field names.
- Validate untrusted input with Form Requests, and authorize every customer-scoped operation.
- Keep API contracts and OpenAPI documents synchronized with implementation.
- Avoid breaking endpoint changes without versioning and migration guidance.

## Verification
- Add automated coverage for success, failure, authorization, pagination and idempotency.
- Run relevant tests and full suite before production releases.
- Run lint/static analysis where configured. Report test status accurately rather than assuming.
- Never deploy code and migrations out of sequence. Verify live endpoints after deployment.

## Maintainability
- Prefer expressive names and small units over clever abstractions.
- Avoid hard-coded environment details; configure external services through environment variables.
- Keep logs actionable, avoiding customer secrets and sensitive payloads.
- Document architectural decisions and new required infrastructure with the feature.
