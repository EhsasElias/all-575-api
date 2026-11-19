---
name: api-abuse-qa
description: Perform authorized, isolated security and edge-case QA of the ALL 5.75 Laravel API. Use for tenant isolation, IDOR, role bypass, replay, duplicate financial operations, concurrency, rate limits, input fuzzing, and failure injection with evidence-based findings. Never target production without explicit authorization.
---

# ALL 5.75 abuse, concurrency and authorization QA

Use as a focused companion to /api-deep-qa. Test exclusively on a verified local/test environment using test identities; do not perform intrusive scans or send payloads to remote/live systems. Never change firewall or production config, spam emails/SMS, brute-force passwords, or attempt to access another real user's data.

## Preparation

1. Inspect routes/api/*.php, authentication middleware, FormRequests, policies, domain authorization, model scopes, finance/stock services, and test fixtures. List implemented roles; do not invent missing roles.
2. Ensure isolated SQLite test setup from phpunit.xml, while remembering that SQLite may hide MySQL locking and concurrency bugs. When relevant, propose additional isolated MySQL verification; do not claim SQLite proves production-level atomicity.
3. Construct two unrelated seller tenants, distinct customer users, authorized and unauthorized admins/reviewers, and isolated records using factories or PHPUnit fixtures. Never reuse live identifiers.

## Threat-oriented test matrix

- Authentication: guest, invalid/expired/rotated token, wrong token ability, disabled account, session/token replay; verify 401 vs 403 without leaking data.
- Ownership / IDOR: swap order, store, branch, staff, attachment, invoice, ledger, refund and document identifiers across tenants for every read, mutation, and export. Test list filters and aggregate dashboards for indirect leaks too.
- Input and validation: required, null, empty, huge, floats/negative/overflow money, malformed dates, negative quantities, duplicate SKU, path-like uploads, arbitrary MIME, CSV formulas, Unicode and translation fields. Ensure failures do not partially persist data.
- Replay/idempotency: repeat seller actions, checkout, webhook, returns, refund requests, approvals, and import retry with identical and conflicting idempotency keys. Assert only allowed single side effect.
- Concurrency: simulated two checkout requests for last stock; competing stock edits; simultaneous cancellation/refund; repeated callbacks. Verify locking and actual persisted quantities/ledger totals, not only response codes.
- Workflow/state: skip prerequisite approval, use revoked seller access, change a branch while exporting, finish delivery or refund before allowed transition. Verify audit trail and consistent error codes.
- Resilience: provider timeout, storage failure, queue retry, malformed callback, out-of-order event. Confirm no inconsistent committed state or phantom success.
- Boundaries: pagination, filtering, sorting, malformed route IDs, numeric rounding, VAT-inclusive totals, exports and rate-limit enforcement using tiny controlled test requests (never hammer a server).

## Execute and triage

Run smallest relevant PHPUnit feature tests via php artisan test --filter=..., then the full php artisan test after changes are explicitly authorized. Prefer PHPUnit requests over arbitrary network probes; create missing regression tests only on request. For race conditions, state clearly whether real concurrent execution was observed or only simulated. Capture transaction state before/after; redact all secrets and personal fields.

## Output

Return an Arabic risk-ordered report: tested surface and identities, test method, result, P0/P1/P2/P3 severity, endpoint, exact safe reproduction steps, expected vs actual, evidence (test/line/response), business impact, recommended correction, and a regression assertion. Separate CONFIRMED BUG, POTENTIAL RISK, ENVIRONMENT BLOCKER, and NOT TESTED. Never claim penetration-test certification or that the system is secure because checks passed.

## Usage example

/api-abuse-qa Audit seller cross-tenant access and duplicate refunds locally. Report evidence first; do not change application logic.
