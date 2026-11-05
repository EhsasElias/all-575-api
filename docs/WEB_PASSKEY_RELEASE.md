# Web passkey release checklist

This feature is isolated on `feature/web-passkeys`; do NOT merge or deploy to production yet.

1. On the feature branch, run `composer update laravel/passkeys --with-all-dependencies` locally, review and commit `composer.lock`.
2. Run `composer validate --strict`, `php artisan migrate`, and `php artisan test` locally.
3. Do NOT publish the vendor migration: the project includes `2026_11_05_000001_create_passkeys_table.php` already.
4. Configure `PASSKEYS_ENABLED=false`, `PASSKEY_RP_ID=market575.com`, `PASSKEY_ALLOWED_ORIGIN=https://market575.com`, and a long persistent `PASSKEYS_USER_HANDLE_SECRET` in backend `.env`.
5. After security review and tests, deploy backend and frontend together; run migrations and `composer install --no-dev` from the committed lock.
6. Enable `PASSKEYS_ENABLED=true` only after a successful HTTPS end-to-end test, and refresh config cache.
7. Test challenge expiry/replay, wrong origin/RP, invalid signature, account ownership, revoked passkey, wrong enrollment password, user verification and rate limits.
8. Existing email/password and OTP login remain fallback. Fingerprint data stays on the device.

API routes: `GET /api/v1/auth/passkeys/status`, `POST /api/v1/auth/passkeys/options`, `POST /api/v1/auth/passkeys/verify`, `GET /api/v1/me/passkeys`, `POST /api/v1/me/passkeys/options`, `POST /api/v1/me/passkeys`, `POST /api/v1/me/passkeys/{passkey}/revoke`.
