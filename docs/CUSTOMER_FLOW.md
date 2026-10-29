# Customer flow for Saudi individuals and companies

## Shopping from start to finish

1. Launch the app: welcome slides, logo, requested destination. Choose Arabic, English or French and light/dark appearance.
2. Browse as a guest, choose the delivery area, browse categories/subcategories and real product photographs.
3. Search and combine category, company/seller, maximum price, stock in the selected area, and recommended/newest/rating order. Current marketplace unit price remains 5.75 SAR inclusive of VAT; low budgets can correctly return no results.
4. Open product details: seller, description, exact variant, current stock, quantity and verified product reviews. Sign in to save products to the account.
5. Add products to the guest cart. Verified sign-in merges it with the server cart. Review quantities and remove items.
6. Sign up by phone/email OTP, explicitly accept terms/privacy; marketing stays optional. Native biometric unlock is available after verified sign-in. Actual SMS/email delivery requires a configured provider.
7. For company purchases, use Account > Buyer and billing details. Record legal company name, commercial/unified number, VAT number if registered, and billing address. The profile is not business verification.
8. For bulk sourcing, use Account > Quotes and account requests. Include products, quantities, specifications, destination city and required date. Team reply/status is shown in the request list. This is a quotation inquiry; it does not invent a discounted checkout price or a contract.
9. Add a Saudi delivery address and recipient phone. Confirm national-address fields and map pin, delivery coverage and available delivery time.
10. Checkout: confirm server quote, items, discounts/promo, delivery fees, included VAT and total. Add an optional purchase-order reference. Company billing details are snapshotted on the new order.
11. Choose mada, Visa/Mastercard, Apple Pay or cash on delivery. Locally served brand artwork replaces generic payment icons. Only supported online methods can be used when retrying an existing online payment.
12. Online payment: stock/slot are reserved temporarily. Only verified provider confirmation marks money paid. Failure/retry/timeout never silently creates a second order. Real payment processing still needs the contracted gateway.
13. Cash on delivery: the order is confirmed immediately, stock committed, slot retained and purchased cart items removed. No bank session or online charge is created. Payment remains pending and `paid_at` remains null until the full cash receipt is recorded after delivery.
14. Follow order number, delivery window, address, seller deliveries, timeline and ETA. A handover code is distinct from login OTP. Live courier location is displayed only after an operational integration supplies it; none is fabricated.
15. Cancel while allowed. Cancelling uncollected cash restores stock and slot without claiming a refund. Online refunds have separate provider-confirmed statuses.
16. After delivery, review purchased products; request help or a line-level return, choose exact item/quantity/reason and follow messages/status.
17. Review own orders/spending, distinguish order/payment/refund dates and export CSV. Cash awaiting collection is excluded from paid spending.
18. Manage profile, language, appearance, marketing and sessions. Data-copy and account-closure requests are tracked under Quotes and account requests; submission does not immediately erase records.

## Operations for the added flows

Run migrations before using the updated app. Existing catalog data and orders are preserved.

```
php artisan migrate
php artisan optimize:clear
```

Cash must be collected and verified operationally before using the trusted command. `amount` is the exact full order total in integer halalas. The order must be completed. Replaying the same receipt is idempotent; a different receipt is refused. There is no public customer endpoint to mark cash collected.

```
php artisan orders:collect-cash A575-XXXXXXXX 1725 receipt-20261029-0001
```

For development delivery status testing, use `orders:advance` successively with `preparing`, `ready`, `on_the_way`, `delivered`. The helper does not prove real courier handover or cash collection.

Review and respond to a customer's quote/data/closure request:

```
php artisan customers:reply REQUEST_ULID under_review "We are reviewing your request."
php artisan customers:reply REQUEST_ULID resolved "Your request has been reviewed; see the agreed next steps."
```

Resolving the case does not delete accounts, export personal data, issue invoices, change catalog prices or create refunds. Team members must carry out the verified request through the appropriate operational process.

## Integrations and remaining acceptance requirements

The source visual PDF references a companion detailed specification that was not supplied. These are explicit remaining requirements, not implemented promises:

- Contracted SMS/email delivery; live payment gateway and Apple Pay merchant/device certification.
- Compliant electronic tax invoices and credit notes from the approved invoicing solution, including correct B2B/B2C handling and required ZATCA integration. A profile or order summary alone is not an e-invoice.
- Google Maps project/key if Google Maps must replace the current Leaflet/OpenStreetMap address map.
- Operational courier dispatch, customer-safe contact, live location, missed-delivery/rebooking and dedicated handover application.
- Pickup branches, collection capacity/time slots and separate pickup pricing.
- Return evidence uploads, return-window/category rules, inspection/collection operations and approved partial cash/online refunds.
- Seller/courier/order service ratings, review reporting, moderation and appeals beyond existing product reviews.
- XLSX/PDF reporting, invoice document download, channel-specific notification preferences, contact change reverification and production notification delivery.
- B2B quote negotiation/acceptance, minimum quantities/tier prices, approval chains and credit terms if commercially adopted. Current inquiry captures demand without pretending these exist.
- Operational staff screens for cash reconciliation, quote/privacy requests and aftercare; command-line services currently provide controlled operations.

## Source requirements and design references

- Supplied ALL575 Visual Roadmap & Mapping v2, pages 1, 6, 7, 9, 10 and 11.
- Alibaba sourcing guidance: https://activity.alibaba.com/page/how_to_source.html
- Alibaba RFQ description: https://activity.alibaba.com/ggs/request_for_quotation.html
- ZATCA e-invoicing specifications: https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/E-Invoice-specifications.aspx

RFQ, saved products and separate company billing are useful additions to the supplied flow. They do not imply an Alibaba affiliation or buyer-protection/escrow guarantee.
