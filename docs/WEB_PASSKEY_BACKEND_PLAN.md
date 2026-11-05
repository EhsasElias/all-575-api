# WebAuthn passkeys for market575.com (not implemented)

This document defines the backend requirements for browser fingerprint/face
sign-in. As of this change the API does **not** expose passkey endpoints.
The existing email/password and OTP authentication remains authoritative.

## Prerequisites

- Integrate and lock a maintained PHP WebAuthn relying-party library.
- Configure RP ID `market575.com`, allowed origin
  `https://market575.com`, and allowed algorithms.
- Store only credential ID, public key, user relationship, sign counter
  and display metadata; do not store biometric templates.
- Require recent authenticated session for registration, listing and deletion.
- Enforce unique credential IDs, account ownership, server-side user
  verification and appropriate lockout/rate limits.
- Generate cryptographically secure short-lived single-use registration
  and authentication challenges, bound to session and purpose.
- Verify WebAuthn challenge, origin, rpIdHash, type, signature,
  authenticator data, user presence and user verification server-side.
- Apply a validated signature counter policy to mitigate cloned credentials.
- Authentication must honor is_active, email verification and existing
  legal/consent checks before issuing a Sanctum token.
- Provide explicit recovery and credential revocation flows and audit logs.

## Suggested routes (design only; DO NOT call until implemented)

Authenticated:
- POST /api/v1/me/passkeys/registration/options
- POST /api/v1/me/passkeys/registration/verify
- GET /api/v1/me/passkeys
- DELETE /api/v1/me/passkeys/{credential}

Guest:
- POST /api/v1/auth/passkeys/options
- POST /api/v1/auth/passkeys/verify

The frontend intentionally displays a web-passkey unavailable status until
these routes and integration tests exist.

## Tests before release

- Challenge replay, expiration, cross-user/cross-session challenge
- Mismatched origin or RP ID; invalid signature
- No user verification; revoked/inactive credential
- Enumeration prevention, rate limits and account deactivation
- Safe recovery when a device is lost
- Real browser HTTPS end-to-end passkey enrollment and sign-in
