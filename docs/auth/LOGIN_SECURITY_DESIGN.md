# Market575 authentication decision — email first, optional phone OTP

Status: **Approved design / not yet implemented**. This document records the selected design and is not a claim that Firebase sign-in is working on the website or Android.

## Customer experience

1. The default sign-in form requests **email + password**.
2. New customers verify their email address using Firebase Authentication's email-verification link before being issued a Market575 API session.
3. Offer **Forgot password** using Firebase's password-reset email.
4. Provide **Sign in with a phone code (OTP)** as a clearly labeled *alternative*, not the default. Allow access to the same account only when the phone number was previously linked through a stronger authenticated session. Do not create or merge accounts by unverified matching data.
5. Keep customer Terms & Privacy acceptance when creating accounts, and preserve marketing consent as optional.
6. Support passkeys/WebAuthn and phishing-resistant MFA as a later phase; for sensitive actions require recent reauthentication. Do not present SMS-only sign-in as equivalent to MFA.

## Architecture

- Frontend: `all-575-android`, React/Vite/Capacitor, shared web and Android UI.
- Identity verification: Firebase Authentication for email/password and optional verified phone credentials.
- Backend: `all-575-api` Laravel. The server validates Firebase ID tokens **server-side** using a trusted Firebase Admin SDK or a properly maintained verifier, checks issuer, audience, signature, expiry, revocation as required, and verifies `email_verified` for email sign-in.
- The server then resolves an existing verified identity or creates an account with required consent, and issues an existing Laravel Sanctum customer session. Firebase ID tokens never substitute for Laravel API access tokens at business endpoints.
- Existing MySQL users, orders, addresses, consents and sessions must be preserved. Introduce a unique `(provider, firebase_uid)` identity mapping; avoid silent merges and account takeover.

## Secure implementation sequence

1. Add backend token-verification service, credential configuration, identity-linking migration, consent handling, an idempotent /api/v1/auth/firebase exchange endpoint and focused security tests.
2. Extend the OpenAPI contract owned by the backend, then update the frontend's pinned contract and generated types.
3. Add React email/password sign-up, login, verification confirmation, reset password and error states. Default to email; phone OTP remains an opt-in path. Do not allow an unverified Firebase email to create a Laravel customer session.
4. Integrate a Firebase phone-number proof flow including required reCAPTCHA for web and native Android verification setup separately. Only enable phone login for correctly linked accounts.
5. Validate browser CSP/auth domains and dedicated Android package + SHA setup, and test in an isolated environment before production deployment.
6. Add MFA/Passkeys in a dedicated rollout with a recovery policy and reauthentication gates.

## Operational guardrails

- Never expose Firebase Admin service-account keys, Laravel APP_KEY, or SMS provider credentials via `VITE_*`, `public/config.js`, a web bundle, or an Android APK.
- Never turn on Laravel's `OTP_DRIVER=log` or simulated payment providers in production.
- Firebase Console enablement, a web API key, or `npm install firebase` **do not** complete integration.
- Configure identity provider quotas/billing and abuse prevention before real SMS testing.
- Use controlled feature rollout, automated tests and rollback plan before changing the live login experience.

## Acceptance criteria

- A new user can create an email/password account, verify the email link, accept Terms and Privacy, and then sign in to one Laravel customer profile.
- Returning verified users can sign in and recover passwords; invalid and unverified email login attempts do not receive a Sanctum session.
- A phone account cannot take over an email account by entering the same unverified email or phone.
- Existing users' orders and addresses remain attached to their original accounts.
- Web and Android work against `https://api.market575.com/api/v1`; failures show actionable, localized errors.
- Authentication attempts are rate limited and audited without logging passwords or raw tokens.
