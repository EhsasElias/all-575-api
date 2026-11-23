# ALL-5.75 delivery / integration acceptance checklist (through 27 Nov 2026)

This checklist is not evidence of completion. Record the environment, timestamp, redacted response, screenshot and reviewer for every check.

## Delivery confirmation and failed delivery
- [ ] Issue a delivery-only proof or OTP for an authenticated customer after pickup; never expose it in courier list endpoints.
- [ ] Reject incorrect, expired or repeated proofs, and keep delivered transition atomic across suborder, courier assignment, overall order and audit event.
- [ ] A failed attempt by an assigned approved courier records an incident and order history without marking delivered; duplicates and foreign users rejected.
- [ ] Completed courier jobs show actual history; earnings must never assume a payout.

## Payment, preparation, stock and tracking
- [ ] Verified provider payment event, not a browser return, confirms payment.
- [ ] Reservation and stock never go negative or violate cancellation and refund transitions.
- [ ] Only paid, seller-ready orders can be dispatched to approved online couriers in their service zones.
- [ ] Concurrent duplicate assignments and repeated pickup actions are rejected.
- [ ] Customer, seller, admin and courier screens agree after preparation, pickup, failure and delivery.
- [ ] Notifications are user-scoped and do not disclose verification secrets or personal data.

## QA, translation and deployment
- [ ] API role tests for customer, seller, admin, super admin and courier.
- [ ] Arabic RTL, English and French UI tested at mobile, tablet and desktop widths.
- [ ] Isolated composer lint and PHPUnit suite pass on disposable test DB.
- [ ] Frontend lint, typecheck, build, locale audit, API contract and e2e pass or known baseline failures are documented.
- [ ] Paid-to-delivered end-to-end staging test covers invalid proofs and recovery.
- [ ] 26-27 November live-domain smoke: URL, TLS, assets, release SHAs, login, catalog, stock, seller preparation, courier assignment, tracking and access scope.
- [ ] Record evidence, remaining limits, rollback and manager sign-off. Never send live payments or OTPs just for testing.

## Current verified progress
As of 22 Nov 2026 only failed-delivery incident reporting and initial authorization tests are committed on the QA branch. These checks are not yet executed or passed.