# ALL 5.75 API

Laravel 13 / PHP 8.3 API for the ALL 5.75 customer app.

**Front end:** [EhsasElias/all-575-android](https://github.com/EhsasElias/all-575-android), branch `v2`. The two repositories share one thing, the API contract
([docs/api/openapi.json](docs/api/openapi.json)). This repository owns it: the tests here fail when it no longer matches the code. The front end keeps a copy
pinned to one commit of this repository in its `api-contract.json`, and its CI fails when the copy and this repository disagree.
After you change the contract here (`composer openapi`), commit it, then run `npm run api:update` in the front end.
The machine-readable contract is [docs/api/openapi.json](docs/api/openapi.json) (regenerate with `composer openapi`); how to use it is in [docs/api/README.md](docs/api/README.md).

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
- A refund that could not reach the provider stays `pending` and `payments:retry-refunds` (scheduled every 5 minutes, so `php artisan schedule:run` must run every minute) sends it again. A callback that arrives before we have saved the charge's reference is answered `409` and not remembered, so the provider's retry is processed.
- Stock is held with single conditional `UPDATE`s, so two buyers cannot take the last unit. Unpaid holds expire (`orders:expire-unpaid`, every minute).
- Business refusals are `DomainRuleViolation`s and reach the app as `{ "message", "code", ... }`; the app switches on `code`.

## Before production

These are intentionally unconfigured and refuse to work until decided (decisions D-02, D-03, D-04 and D-09):
SMS/email provider (D-03), payment gateway (D-02), VAT/invoice model (D-04), delivery slot model (D-09).
Also set `TRUSTED_PROXIES`, `CORS_ALLOWED_ORIGINS`, a real database, and `APP_DEBUG=false`.

## Deploy on Railway (a test server)

`railway.json` and `railway/predeploy.sh` are all Railway needs: it builds the app with Nixpacks, runs the migrations (and creates delivery times) before each
release, and checks `/up`. In a Railway project: **New > GitHub repo > this repository, branch `v2`**, add a **MySQL** or **PostgreSQL** database, then set the variables below.

| Variable | Value |
|---|---|
| `APP_KEY` | the output of `php artisan key:generate --show` |
| `APP_ENV` | `local` for a test server: it is what turns on sign-in codes in the log and the test payment. In `production` the app refuses to send codes or take payments until a provider is chosen (D-02, D-03). |
| `APP_DEBUG` | `false` |
| `APP_URL` | the service's public address, `https://...up.railway.app` (product pictures use it) |
| `DB_CONNECTION` | `mysql` (or `pgsql`), and `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from the database service, for example `${{MySQL.MYSQLHOST}}` |
| `OTP_DRIVER` / `PAYMENT_DRIVER` | `log` / `fake` |
| `FAKE_PAYMENT_WEBHOOK_SECRET` | any long random text |
| `LOG_CHANNEL` | `stderr`, so the sign-in code appears in Railway's logs (a line "OTP issued (log driver)") |
| `TRUSTED_PROXIES` | `*` (Railway terminates HTTPS in front of the app) |
| `CORS_ALLOWED_ORIGINS` | the front end's address, `https://...up.railway.app`, plus `capacitor://localhost,https://localhost` for the Android app |
| `SEED_DEMO_DATA` | `true` for the first deployment only, then remove it |

The scheduled work (`orders:expire-unpaid`, `payments:retry-refunds`, `slots:generate`) needs a second service from the same repository with the start command `php artisan schedule:work`.
A test server with `APP_ENV=local` is not for real customers: anyone who knows a payment id can play the payment provider.
This has not been run on MySQL or PostgreSQL yet (the tests use SQLite): watch the first deployment's logs.

## Customer support and return requests

Customers can open an order issue or a delivered-item return request from the customer app, follow replies under Account > Support and returns, and send follow-up messages while a case is open. Run `php artisan migrate` after updating this branch.

The authenticated customer endpoints are `GET /me/cases`, `GET /me/cases/{case}`, `POST /orders/{order}/cases`, `GET /orders/{order}/care-items`, and `POST /me/cases/{case}/messages` under `/api/v1`. Create requests use a UUID `request_key`: retrying the same payload returns the original case; reusing a key with another payload is refused. Return requests require a delivered order line and cannot exceed its unrequested quantity. Rejected requests release that quantity for a new request.

For local/testing support replies use:

```bash
php artisan care:reply CASE_ID "We are checking your request." --status=in_review
php artisan care:reply CASE_ID "Outcome explained here." --status=resolved
```

The command also accepts `rejected`. It is unavailable outside local/testing. Opening/resolving a case never issues a refund, restores stock or confirms money received. Production support permissions/UI, return policy/collection/inspection, evidence attachments and return-triggered partial refunds are still required. See the [customer map](https://github.com/EhsasElias/all-575-android/blob/v2/docs/customer/CUSTOMER_MAP.md) and [testing guide](https://github.com/EhsasElias/all-575-android/blob/v2/docs/customer/CUSTOMER_TEST_GUIDE.md) for the full requirement and test matrix.

## Demo catalog photographs

The active demo categories and products use real photographs. See [photo sources and local refresh steps](docs/catalog-photo-sources.md). Pulling these image files does not change your database or stock; hard-refresh the app after updating the API repository.
