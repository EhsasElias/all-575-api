# Customer API: how to talk to it

The contract is [openapi.json](openapi.json) (regenerate with `composer openapi`; a test fails if it drifts).
This page is what the contract cannot say.

## Basics

- Base path `/api/v1`. JSON in, JSON out. Send `Accept: application/json`, and `Accept-Language: ar` or `en`.
- **Money is an integer number of halalas** (`575` = 5.75 SAR) and **every price already includes VAT**. Never send an amount to the server: it prices everything itself.
- **Everything costs 5.75 SAR** (5.00 + 0.75 VAT; zero-rated goods are 5.75 with no VAT). There is no per-product price, price filter or price sort. `GET /store` returns the price, VAT rate and the current Terms/Privacy versions, so the app never hard-codes them.
- Single resources come wrapped as `{ "data": ... }`. Lists add `links` and `meta` with cursor pagination (`links.next` is a full URL; follow it as is).
- Names are bilingual objects: `{ "ar": "...", "en": "..." }`.
- Dates are ISO 8601 in UTC. Show them in `Asia/Riyadh`.

## Signing in (no passwords)

1. `POST /auth/otp/request` with `{ channel: "phone", destination: "05..." }` returns `challenge_id`, `expires_in`, `resend_after` (HTTP 202).
2. `POST /auth/otp/verify` with `{ challenge_id, code, device_name }`.
   - A **new** account must also send `consents: { terms_version, privacy_version, marketing? }` with the versions the person saw and accepted. Without them the answer is `422 consent_required` carrying the current versions. The checkboxes must start **unticked**.
   - Success returns `token`; send it as `Authorization: Bearer <token>`. Keep it in the Android Keystore, never in web storage. `401` means sign in again.
3. `DELETE /auth/session` signs out this device; `GET /me/sessions` and `DELETE /me/sessions/{id}` manage other devices.

## Errors

Business refusals look like `{ "message": "...", "code": "snake_case", ...extra }` and **the app switches on `code`**, not on `message`.
Form problems are Laravel's `422 { message, errors: { field: [..] } }`. `429` carries `Retry-After` (seconds).

Codes the screens must handle: `consent_required`, `otp_invalid` (`attempts_remaining`), `otp_expired`, `otp_locked`, `otp_cooldown`, `account_inactive`,
`address_not_serviceable`, `address_limit_reached`, `cart_empty`, `quantity_out_of_range`, `variant_unavailable`, `promo_*`,
`quote_expired`, `quote_changed` (includes the new `quote`), `cart_has_unavailable_items` (includes `issues`), `out_of_stock`, `slot_full`, `slot_too_soon`, `slot_invalid`,
`payments_unavailable`, `payment_provider_error`, `payment_window_closed`, `order_not_payable`, `order_not_cancellable`, `idempotency_key_reused`,
`delivery_code_unavailable`, `review_not_allowed`, `review_exists`, `review_window_closed`.

## The buying flow

```
guest: GET /welcome, /categories, /products?zone_id=, /products/{slug}   (no sign-in needed)
sign in -> POST /cart/merge {items:[{variant_id, quantity}]}               (adds the guest's local cart)
address -> POST /addresses (SPL fields + map pin) -> serviceable? else offer another address
cart    -> PUT /cart/items/{variant} {quantity}   (0 removes)
review  -> POST /quotes {address_id, promo_code?}   -> total, VAT, delivery fee, issues, can_checkout; valid ~10 min
slot    -> GET /delivery/slots?address_id=
pay     -> POST /checkout {quote_id, slot_id, payment_method}  + header Idempotency-Key: <new uuid per attempt>
           returns the unpaid order and payment.action (provider session). Open the provider's page or SDK.
confirm -> poll GET /payments/{id} until status is paid | failed | expired. Coming back to the app proves nothing.
failed  -> POST /orders/{id}/payments {payment_method} to try another card while the hold lasts (15 min).
track   -> GET /orders, GET /orders/{id} (timeline, suborders, eta), GET /orders/{id}/suborders/{sid}/delivery-code
```

Rules the UI should reflect:
- Only call `/checkout` with the quote the customer just saw. `409 quote_changed` returns a fresh quote: show it again, do not retry silently.
- Reuse the same `Idempotency-Key` when retrying the *same* checkout after a lost response; use a new one for a new attempt.
- Unavailable lines are returned flagged (`available: false`), never silently removed. `can_checkout` is the single switch for the pay button.
- A refund is "done" only when `refunds[].status` is `succeeded`; show `pending` honestly. Order date, payment date and refund date are different fields.
- A customer may cancel while `can_cancel` is true (before the seller starts preparing).

## Status codes the contract file records as 200

The exporter documents these annotated responses as `200`; the real codes are `202` (`/auth/otp/request`), `201` (`/quotes`, `/checkout`, and `/auth/otp/verify` for a new account). Treat any `2xx` as success.

## Local development

`php artisan serve`, then sign in with any Saudi mobile number; the code is in `storage/logs/laravel.log`.
`POST /dev/payments/{payment_id}/simulate {outcome: "succeed"|"fail"}` plays the payment provider (local and testing only).
