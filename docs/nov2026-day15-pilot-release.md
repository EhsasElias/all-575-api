# ALL 5.75 — Day 15 pilot release and manager demo (27 November 2026)

> **Status: code committed for review, NOT deployed or production-verified.**
> The source workbook's **Detailed Daily Tasks** sheet schedules Day 15 on 27 Nov 2026.
> It separately schedules **Day 16 (30 Nov)** and **Day 17 (1 Dec)** as live-domain tests.
> Do not call the project live-verified on Day 15.

## Safety gates — finish before any deployment

1. Do not merge these features into `v2` automatically. Work from
   `feature/nov2026-days08-17`, and cherry-pick only approved day-specific commits.
2. Back up the database; test migrations against a cloned staging database first:
   `php artisan migrate --force` (staging only).
   The courier migrations add `courier_profiles`, `courier_assignments`,
   `courier_assignment_events`, `delivery_incidents`, and
   `courier_delivery_attempts`.
3. On a development/staging environment:
   `php artisan test --filter=CourierOperationsTest`;
   `php artisan test --filter=CourierEndToEndTest`;
   `php artisan test`;
   `php artisan route:list --path=api/v1/courier`;
   `php artisan route:list --path=api/v1/platform/admin`.
4. Review failures and migration status; do not deploy while critical failures remain.
   Verify PHP extensions and environment-specific secrets; never commit real keys.
5. Test on the matching frontend feature branch. Deploy both repos to an
   **approved staging** environment together, not the public domain by default.

## Manager demo — exact end-to-end path

- Super Admin approves a verified courier with a valid service zone.
- Courier signs in and goes online.
- Customer pays through a provider-confirmed payment (or an approved test gateway
  in local/testing environments); seller marks the suborder ready.
- Admin sees it as dispatch-eligible and offers it to the same-zone courier.
- Courier accepts; a duplicate accept/assignment must be rejected.
- Admin obtains a seller handover code; wrong code is rejected and attempts counted.
- Courier enters the valid handover code and parcel count. Suborder becomes `on_the_way`.
- Customer can then read a **separate** delivery code from their order.
- Courier enters the customer code; wrong code must not mark delivered.
- On valid code, suborder becomes `delivered`, and the parent order becomes
  `completed` only when all parts are delivered/cancelled.
- Customer order timeline, seller orders, Admin queue and notifications agree.
- A failed delivery records a delivery incident and does not falsely mark delivered.
- Cross-seller Admin access, stranger couriers, expired offers and repeated actions
  are denied.

## Known limitations and dependencies

- No live GPS or automatic dispatch optimization; map navigation uses the
  stored branch pickup coordinates.
- No automated courier payouts; any earnings display is explicitly unconfigured.
- Refund incident records are **follow-up tickets**, not actual payment refunds.
- Courier registration uses an existing verified user ID and service-zone ID.
- The payment provider must be configured. Browser return alone cannot confirm payment.
- Current dispatch rules intentionally require a provider-confirmed paid record;
  cash-on-delivery dispatch needs an explicitly approved policy and tests.
- Tests above are instructions, not evidence of tests actually passing.
- Live domain and real gateway/notification checks need operator credentials and
  permission to deploy; no live verification was performed here.

## Day 16–17 follow-up, not part of the 27 November milestone

For each live test log: actual URL, test account role, timestamps, observed result,
request/response status, screenshot or log reference, fix commit, retest status,
and final sign-off. No production or staging success claims without evidence.

## Cherry-pick discipline

Each change uses a `Day NN:` commit subject so a reviewer can locate it:

```bash
git log --oneline --grep='Day 13:'
git log --oneline --grep='Day 14:'
git log --oneline --grep='Day 15:'
```

A day's work may have multiple commits. Cherry-pick them **oldest to newest**,
in one repository at a time, only after checking dependencies. Do not cherry-pick
Day 13–15 without checking the courier foundations from Days 6–12.
