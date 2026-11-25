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
