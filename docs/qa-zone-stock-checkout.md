# Stock availability and checkout QA — 2026-11-25

## Scope
QA branch only. **Do not deploy to market575.com or run database migrations/seeds** as part of this fix.

## Root cause confirmed by source review
- `Availability::forVariants` previously **summed** unreserved stock across every eligible branch in the delivery zone.
- `StockReservations::holdOne` can fulfil one cart line from **only one branch**, without splitting an order line.
- A cart line needing 5 units could appear available with 2 units at one branch and 3 at another, even though no branch could fulfil it.
- `CartResource` previously used an availability badge based on total free units **without considering the line's requested quantity**, so 2 remaining could show "low stock" even when the cart requested 3.

## Changes
- `Availability::forVariants` reports max nonnegative unreserved units from one active eligible branch.
- `Availability::forProducts` aggregates the strongest eligible branch per active variant, for consistent catalogue status.
- `CartResource` reports `out_of_stock` for a cart line if `available_units < requested_quantity`.
- Frontend companion branch `fix/qa-home-reference-layout-20261124` hides unavailable items from home shelves **only after a customer has chosen a delivery zone**, shows out-of-stock badges prominently elsewhere, and disables the cart checkout link if any line is unavailable.
- Server-side `QuoteBuilder`, `PlaceOrder` and `StockReservations` remain the final authority; no bypasses.

## IMPORTANT remaining diagnostic
A product can legitimately be out of stock **for the selected zone** while still having on-hand inventory in another zone, a disabled branch, or stock reserved by orders. Verify the actual customer's zone and the owning store's branch zone/coverage before assuming a calculation bug.

## Safe verification
`phpunit.xml` configures `testing` with SQLite `:memory:`. Inspect effective config and run **only the isolated test suite**, never in production:
```sh
php artisan test --filter=ZoneAvailabilityMatchesReservationTest
php artisan test --filter=CartQuantityAvailabilityTest
```
The new tests cover multiple branches with insufficient unsplittable quantities, reserved units, inactive/out-of-zone branches, active variants, and a cart quantity exceeding stock.

## Rollout criteria
1. Targeted plus wider backend tests green in a disposable DB.
2. Frontend tests/build green.
3. Stage in `test.market575.com` / `api-test.market575.com` and verify real product+branch coverage per Riyadh delivery zone, stock reservations, low-stock quantities, and account/guest flows.
4. Confirm an unavailable item cannot be added or purchased, and mixed available/unavailable carts cannot proceed to checkout.
5. Deploy production only by the user's explicit approval after QA and rollback plan.


## Revised requirement — stock is not delivery-area constrained (2026-11-25)

Per owner decision, a courier may collect a product from any active branch and
deliver it outside that branch's configured service-zone coverage. Availability,
search `in_stock` filtering, cart stock badges, and final stock reservations now
look across **all active branches**, regardless of the shopper's selected area.
Available units remain `max(0, on_hand - reserved)`, and a single cart line
must fit in one branch; order reservation remains atomic.

**Separate remaining limitation:** checkout's address validation, price calculation,
and delivery-slot selection still require a configured service zone. This is not a
stock requirement. Supporting deliveries outside *all* configured zones needs a
safe courier dispatch/fee/slot policy and must not silently pick an unrelated
region's fee or delivery slot. Do not claim nationwide delivery until that policy
is implemented and tested.

**Validation:** Run the stock-related integration tests from disposable SQLite
and the wider checkout/cart suites, as previous zone-filter assumptions may need
updating. No production data has been changed.
