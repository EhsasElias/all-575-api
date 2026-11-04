# Local seller account for ALL-5.75

Use this to test the seller workspace **without approving a real applicant**.
The catalog already seeds the approved **Home Store** (`home-store`) and its
demo products and branch. This opt-in seeder creates a separate local test
identity and grants it the **owner** membership for that demo store.

It is **not** an admin, reviewer, or a real seller application approval.
Review of real applications still happens through the admin reviewer workflow
(`seller_review_grants`, application review and audited decisions).

## Setup (local only)

1. Choose an email address you control and can receive verification codes at.
2. Add the following to the **Backend** `.env` file:

   ```dotenv
   APP_ENV=local
   DEMO_SELLER_EMAIL=your-test-email@example.com
   ```

3. Run:

   ```powershell
   php artisan optimize:clear
   php artisan migrate
   php artisan db:seed
   ```

   Or, if the demo catalog already exists, run
   `php artisan db:seed --class=DemoSellerAccountSeeder`.

4. In the app, choose the **email OTP** sign-in path using the exact email
   from `DEMO_SELLER_EMAIL`. Accept the current Terms/Privacy if prompted.
   After sign-in, open **Account → Seller workspace**; it should show the
   seeded Home Store membership. No password is created by this seeder.
   If `OTP_DRIVER=log`, the code is in the local Laravel log; when
   `OTP_DRIVER=mail`, the code is sent through configured SMTP.

**Safety:** This seeder refuses to run in production. The regular
`DatabaseSeeder` only invokes it in non-production environments and when
`DEMO_SELLER_EMAIL` is nonempty. It never reassigns an existing unrelated
account; if the email already exists without that demo owner's membership,
it fails deliberately rather than granting privileges. Repeated runs are
idempotent for the account it created. Do not set this email to an admin or
real customer account.

The seeded seller is already approved solely for **local testing**, because
the existing demo catalog is pre-approved. **Real sellers must apply and
be approved by a separately authorized admin**. Do not use this account or
seeded store as a production merchant.

## Tests

```powershell
php artisan test --filter=DemoSellerAccountSeederTest
php artisan test
```
