# ALL 5.75 - Day 1 integration audit (v2)

Date: 2026-11-09
Scope: Customer -> Seller -> Admin -> Courier -> Customer, including Super Admin controls.
This is a **source-code audit**, not proof that production integrations have passed end-to-end testing.

## Confirmed reusable building blocks

| Area | Verified code | Current interpretation |
| --- | --- | --- |
| Customer checkout | `routes/api/checkout.php`, `CheckoutController::store` | Creates an unpaid order and payment attempt; idempotency key is passed to `PlaceOrder`. |
| Customer order status | `OrderController::index/show` | Customer-scoped order history and detail. |
| Payment callback | `routes/api/checkout.php` -> `PaymentWebhookController` | Webhook endpoint is wired; provider success still needs live verification. |
| Seller order queue | `routes/api/seller.php`, `SellerWorkspaceController::orders` | Scoped to approved seller/staff membership and branch. |
| Seller preparation | `SellerWorkspaceController::advance`, `SuborderTransitions` | Reuses a guarded order state machine and timeline records. |
| Customer delivery code | `DeliveryCodeController`, `DeliveryCodes` | Customer can see an active delivery code; verification service already exists. |
| Review permissions | `SellerReviewController`, `seller_review_grants` | Reviewer access is explicitly granted; not inferred from customer authentication. |
| Seller products / stock | `SellerCatalogWorkspaceController`, `routes/api/seller.php` | Seller catalog and branch inventory APIs exist. |

## Confirmed gaps in inspected API routes

1. `routes/api.php` registers identity, catalog, addresses, cart, checkout, aftercare, seller and finance route groups, but **does not register a courier or delivery operations route group**.
2. In the inspected route groups, no courier-authenticated job list, accept/reject, pickup verification, delivery-code submission, or reassignment endpoints are exposed. The existing `DeliveryCodes::verify` is a reusable internal service, **not a courier endpoint**.
3. The reviewed route groups do not expose a dedicated Admin dispatch queue/assignment API or global Super Admin account-management API; their full implementation status requires review of additional controllers, models and frontend screens.
4. Payment processor success, inventory reservation behavior, multi-branch order handoff, and customer notification delivery **cannot be certified from route inspection alone**.

## MVP implementation order (do not bypass authorization)

- **Day 1:** Review existing domain models, roles and tests; confirm reuse; document missing API contracts; add route-access regression tests.
- **Super Admin:** Verify account provisioning, scoped permissions, seller approvals, configuration and audit.
- **Admin:** Build authorized operational order queue, courier approval and **manual** courier assignment/reassignment.
- **Courier:** Add distinct courier identity/permission checks, assigned-jobs list, safe accept/decline, pickup proof, delivery-code verification, and exception reporting.
- **Shared integration:** Client checkout/payment confirmation -> seller order/preparation -> Admin dispatch -> courier pickup -> customer code -> delivered order + tracking.
- **Testing:** Cross-role access, invalid order transitions, duplicate action protection, courier reassignment, cancelled orders, payments, stock, delivery code expiry/retries, and real staging deployment.

## Operational requirements before launch

- Require an authorized actor for **every** courier/admin state transition; never expose `SuborderTransitions::move` directly to arbitrary customer sessions.
- Keep delivery pickup verification separate from customer delivery confirmation.
- Prevent duplicate courier acceptance, duplicate delivery completion, and reassignment after successful pickup without an audited exception path.
- Live tracking should be limited to an active assigned delivery and stopped when the job closes.
- Ensure successful payment and stock hold correctness before dispatch.
- Maintain seller, branch, courier, and Admin-scoped data isolation.

## Acceptance criteria for Day 1

- [x] Read the registered API route groups on `v2`.
- [x] Verify the shared checkout/order, seller preparation, review and code services exist.
- [x] Identify the missing courier-facing route surface in the inspected API.
- [x] Record a shared integration contract and prioritised risks.
- [ ] Run backend and frontend tests in a checkout of `v2`.
- [ ] Confirm deployed/staging behavior and external payment-provider readiness.
- [ ] Audit full admin UI / finance implementation and relevant database migrations.

The checklist distinguishes verified code from work that requires additional source access and runtime testing.
