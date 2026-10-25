# ALL 5.75 API

Laravel 13 / PHP 8.3 API for the customer app. Plan and phase list: [docs/customer-roadmap.md](../../docs/customer-roadmap.md).
The machine-readable contract is [docs/api/openapi.json](../../docs/api/openapi.json) (regenerate with `composer openapi`).

## Run it

```bash
composer setup            # install, .env, key, migrate, demo data and delivery slots (local only)
php artisan serve         # http://localhost:8000
php artisan test          # unit + feature tests
composer lint             # code style (Laravel Pint)
```

Codes and payments are simulated locally: the one-time code is written to `storage/logs/laravel.log`, and
`POST /api/v1/dev/payments/{id}/simulate` plays the payment provider (it exists only when `APP_ENV` is `local` or `testing`).
`php artisan orders:advance A575-XXXXXXXX preparing|ready|on_the_way|delivered` stands in for the seller and courier apps.

## How it is organised

```
app/Domain/<Area>/   rules and data: Identity, Catalog, Addressing, Cart, Orders, Payments, Inventory, Reviews, Reporting, Pricing
app/Http/            thin controllers, form requests (validation), resources (response shapes), middleware
app/Support/         Money (integer halalas), SaudiPhone, DomainRuleViolation
routes/api/*.php     one file per area
```

Rules worth knowing:

- Money is always an integer number of halalas (575 = 5.75 SAR) and prices include VAT. VAT is reported once per invoice and rate, never added on top.
- The server prices everything. The app never sends an amount.
- Only a signed, de-duplicated provider callback confirms a payment. Returning to the app proves nothing.
- Stock is held with single conditional `UPDATE`s, so two buyers cannot take the last unit. Unpaid holds expire (`orders:expire-unpaid`, every minute).
- Business refusals are `DomainRuleViolation`s and reach the app as `{ "message", "code", ... }`; the app switches on `code`.

## Before production

These are intentionally unconfigured and refuse to work until decided (see the roadmap, section 6):
SMS/email provider (D-03), payment gateway (D-02), VAT/invoice model (D-04), delivery slot model (D-09).
Also set `TRUSTED_PROXIES`, `CORS_ALLOWED_ORIGINS`, a real database, and `APP_DEBUG=false`.
