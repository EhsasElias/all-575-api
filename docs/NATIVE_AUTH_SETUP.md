# ALL-5.75 Native Authentication (Laravel + MySQL)

Email + password is the primary sign-in method. Firebase Authentication is not used by the app.
Existing single-use OTP endpoints remain available for email verification, account recovery,
and Saudi mobile verification. Laravel Sanctum issues customer sessions after verification.

## Local backend

Install and migrate:

```powershell
cd C:\xampp\htdocs\all-575-api
git pull origin v2
php artisan migrate
php artisan optimize:clear
php artisan route:list --path=api/v1/auth
php artisan test
```

For **local development only**:

```dotenv
APP_ENV=local
OTP_DRIVER=log
```

The test/log driver writes codes to `storage/logs/laravel.log` and is blocked in production.
Do not expose local application logs or OTP values to users.

## Production email delivery

Use a company SMTP account or an approved transactional email provider.

```dotenv
OTP_DRIVER=mail
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=no-reply@market575.com
MAIL_FROM_NAME="ALL 5.75"
```

Never commit real passwords. Test actual delivery to the intended inbox before going live.
For production, do not use a non-delivering `log` or `array` mail transport.

## Optional Saudi SMS via Taqnyat

The gateway account, API bearer token, **approved sender name**, message credits and any required
IP allowlisting must be provided by the company. Set:

```dotenv
OTP_DRIVER=taqnyat
TAQNYAT_BEARER_TOKEN=your-provider-issued-secret
TAQNYAT_SENDER_NAME=your-approved-sender
```

This driver sends email OTP with Laravel Mail and phone OTP through Taqnyat.
The SMS API receives mobile numbers in international format without `+`.
Until credentials are valid and the sender name is active, SMS delivery returns an unavailable error.
No SMS token, OTP or email password is hardcoded in the repository.

API documentation: https://dev.taqnyat.sa/en/doc/sms/

## Frontend

```powershell
cd C:\xampp\htdocs\all-575-android
git pull origin v2
npm run build
npm run dev
```

Configure `VITE_API_BASE_URL` for the intended Laravel environment.
`VITE_FIREBASE_*` values are no longer required for the primary sign-in UI.
Use the correct backend `.env`, not the Vue `.env.local`, to configure OTP delivery.

## New API routes

- `POST /api/v1/auth/email/register`: create an **unverified** email/password account; send OTP.
- `POST /api/v1/auth/otp/verify`: verify the code and issue a customer token.
- `POST /api/v1/auth/email/login`: authenticate verified email/password accounts.
- `POST /api/v1/auth/otp/request`: send or resend email/phone OTP (rate limited).
- `POST /api/v1/auth/email/password-reset`: require a **fresh email OTP**, update password, revoke old sessions.
- `POST /api/v1/auth/otp/verify`: alternative OTP-only sign-in.

Existing OTP-only accounts can use email code recovery to create a local password after email verification.
All seller access remains subject to existing approved membership and role checks.

## Release gate

1. Test migration and `php artisan test` locally.
2. Generate and verify API schema (`composer openapi`, then OpenAPI contract test).
3. Run frontend `npm run build`.
4. Test registration, resend cooldown, verified login, wrong password, recovery, seller login, and mobile OTP.
5. Configure SMTP and SMS providers before enabling production signups.
6. Do not publish backend-only or frontend-only auth changes separately.
7. Back up the database and verify a rollback plan before migrating production.
