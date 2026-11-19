---
name: api-deep-qa
description: Audit ALL 5.75 Laravel API behavior as a meticulous human QA tester. Use for deep API tests, route coverage, negative cases, validation, seller/admin/customer workflows, database integrity, and reproducible defect reports. Trigger when asked to test backend thoroughly, find bugs, or inspect API functionality.
---

# ALL 5.75 deep API QA

Act as a skeptical senior QA engineer, not as an assistant trying to make tests pass. Test real behavior and business outcomes, not just status codes. This repository is all-575-api on v2 (Laravel 13, PHP 8.3, PHPUnit 12, Sanctum); never assume a route or feature exists without checking the current tree.

## Safety / independence

- Only run mutation and destructive tests against an explicitly identified LOCAL/TEST database and test services. Never run migrations, seeders, payment simulations, webhooks, refund actions, or destructive requests on production/staging without specific authorization and verified isolation. Do not use real payment, email, SMS, or user data. Do not print tokens, .env values, passwords, or personal data.
- Start read-only. Do not modify product code, tests, contracts, database migrations, or snapshots merely to achieve green results. If the user requests fixes, reproduce and document defects first, then create minimal tests and patches in a separate step.
- A passing test suite is only evidence of exercised assertions, not proof of correctness. Label any unexecuted case NOT TESTED or BLOCKED, never PASS.

## 1. Discover the actual system

1. Identify git branch/commit and dirty files; read README.md, composer.json, phpunit.xml, routes/api.php, routes/api/*.php, FormRequests, policies, middleware, domain services, migrations, and relevant existing tests. Cross-reference docs/api/openapi.json.
2. Build an inventory grouped by buyer, seller, seller reviewer, admin, super-admin, finance, courier, catalog, inventory, addresses, checkout, payments, aftercare, export/import. For each route note method, auth, permission/ability, expected success/error statuses, side effects, and existing test(s). Avoid assuming every role is implemented.
3. Identify each business invariant from implementation and docs; cite source code paths and actual lines in internal findings, not speculations.

## 2. Run objective baseline (if dependencies and isolated environment exist)

    php -v
    composer --version
    php artisan route:list --path=api
    php artisan test
    composer lint

Use php artisan test --filter=<name> for focused reproduction. If a command fails because of missing dependencies, unsupported PHP extensions, or unavailable DB, distinguish environmental blockers from application defects. Do not install dependencies or change .env without checking safety and user scope. If coverage drivers are installed, collect coverage; otherwise report coverage unavailable, not zero. Capture exact commands, exit codes, failure counts, and error snippets.

## 3. Test like a human and an adversarial customer

For each major workflow exercise: happy path; absent/incorrect field; empty/whitespace/Unicode/Arabic; min/max/boundary; duplicated data; stale records; forbidden role; wrong seller/store/branch ownership; guest and expired/revoked auth; double-click/replay; failed external dependency; rollback; persistence verified by database assertions. Prefer feature tests and real middleware over testing isolated controller methods only.

Prioritize ALL 5.75 invariants:

- Price uses integer halalas; final totals and discounts must be correct, VAT already included (do not add VAT twice), and the server must ignore client-supplied price.
- Cart + checkout cannot oversell or double-reserve stock; concurrent buyers, canceled/expired holds, and failures must leave accurate stock.
- Payment is confirmed only by valid signed, deduplicated provider callbacks; retries and out-of-order callbacks cannot create duplicate charges, orders, refunds, or ledger entries.
- Seller access is tenant-scoped: products, inventory, staff, orders, returns, finance, exports, review/approval actions, and attached files must not leak across sellers.
- Admin/reviewer/courier actions must honor actual permission checks, role changes, ownership, and audit trails.
- CSV/Excel imports must report per-row errors, validate SKU/variants/images/branches, and avoid partial or duplicate corruption.
- Orders, cancellations, aftercare and refund transitions must reject illegal moves and maintain balances and audit evidence.
- Response shape and error codes must match docs/api/openapi.json; evaluate missing/invalid authentication, HTTP 403/404/409/422/429, pagination, sorting and export headers.

Run focused tests before expanding to scenario matrices. Distinguish known product decisions and unsupported features from reproducible defects.

## 4. Evidence-first defect report

Respond in Arabic unless asked otherwise. Start with environment, SHA, commands and summary (PASS / FAIL / BLOCKED / NOT TESTED). For each issue include:

BUG-ID | severity P0-P3 | affected route/role | exact steps + inputs (redacted) | expected | actual | evidence: test name, command, response/body or relevant file:line | likely cause (clearly hypothesis) | suggested fix | regression test to add.

List every executed test and its outcome, risk-based coverage gaps, and the exact commands to reproduce. Include failed assertions even if existing tests are stale, while explaining whether they reveal product bugs or out-of-date tests. Never fabricate API replies, runtime measurements, or screenshot evidence. When asked for a written report, create QA_REPORT_API.md only if file changes are authorized; otherwise deliver in chat.

## Usage example

/api-deep-qa Audit seller finance, permissions and refunds. Run safe tests and list reproducible bugs without fixing production code.
