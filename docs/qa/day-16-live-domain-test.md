# Day 16 — Live-domain test I (26 November 2026)

**Execution status: not run.** Live checks require a release-approved human operator. Never make a real payment, use someone else's account or expose passwords/OTPs in evidence.

| Test | URL | Account/fixtures | Expected | Actual | Evidence reference | Defect/fix/retest |
| --- | --- | --- | --- | --- | --- | --- |
| Website availability / TLS / current asset hash | https://market575.com | Public | HTTPS and intended SHA | Not tested | | |
| API health / TLS / current Backend SHA | https://api.market575.com/api/v1/categories | Public | HTTP 200, correct release | Not tested | | |
| Login and role routing | https://market575.com | Approved QA account | Correct dashboard | Not tested | | |
| Browse categories, products and images | https://market575.com | Public / QA | Accurate visible stock and images | Not tested | | |
| Customer cart, checkout and payment state | https://market575.com | Approved sandbox fixture only | No false success; correct VAT/total | Not tested | | |
| Seller preparation and inventory | https://market575.com | Approved QA seller | Valid state transitions, non-negative stock | Not tested | | |
| Notifications in ar/en/fr | https://market575.com | Approved QA users | Correct recipient and translated text | Not tested | | |
| Mobile, tablet, dark/light and RTL/LTR | https://market575.com | Browser | No blocking overflow or untranslated validation | Not tested | | |

For each row: record exact timestamp/timezone, user role (never account credentials), redacted request status, screenshots, linked fix commit and retest outcome.
