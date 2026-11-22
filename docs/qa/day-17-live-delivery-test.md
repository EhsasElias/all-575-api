# Day 17 — Live-domain test II (27 November 2026)

**Execution status: not run.** Only an authorized human operator should exercise live accounts. Use staging for destructive, payment, refund, SMS or OTP scenarios.

| Test | URL | Role | Expected | Actual | Evidence | Fix and retest |
| --- | --- | --- | --- | --- | --- | --- |
| Admin sees eligible paid and seller-ready orders | https://market575.com | Admin | No unpaid/unready dispatch | Not tested | | |
| Courier eligibility and single assignment | https://market575.com | Admin | Approved, online, in-zone; no duplicate | Not tested | | |
| Courier accepts/declines offer | https://market575.com | Courier | Only own unexpired offers | Not tested | | |
| Pickup code and parcel count | https://market575.com | Seller / Courier | Invalid/expired/replayed proof denied | Not tested | | |
| Customer delivery secret issued after pickup | https://market575.com | Buyer | Private six-digit proof, hidden after view | Not tested | | |
| Courier verifies buyer handover | https://market575.com | Courier | Only valid proof marks delivered | Not tested | | |
| Failed delivery / absent customer report | https://market575.com | Courier / Admin | Incident and audit trace; order stays undelivered | Not tested | | |
| Customer tracking and all role notifications | https://market575.com | All approved QA roles | Consistent status, no secret/PII leakage | Not tested | | |
| Scope isolation and disabled roles | https://api.market575.com | Authorized operator | Foreign users denied without disclosure | Not tested | | |
| Manager approval / rollback and final sign-off | Release record | Operator | Evidence, defect list and explicit approval | Not tested | | |

Record a redacted screenshot, timestamp, environment, branch SHA, error and retest for each case. The existence of this checklist is not evidence of completion.
