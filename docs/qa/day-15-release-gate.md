# Day 15 — Release gate and manager demo

Status: **not passed**. This is the execution plan, not a claim that testing or deployment occurred.

## Release conditions

- [ ] Backend composer lint, PHPUnit (including OpenApiContractTest), and security checks pass on the release SHA
- [ ] Frontend formatting, lint, typecheck, unit tests, locale audit, API snapshot, build and browser checks pass
- [ ] Run complete buyer → verified payment → seller preparing → ready → admin dispatch → approved courier acceptance → seller pickup → buyer delivery code → verified delivery flow on disposable staging data
- [ ] Confirm customer, courier, seller and admin have correct visibility and no cross-account access
- [ ] Confirm bad/expired/replayed codes, duplicate requests, failed deliveries, refund/cancellation and stock boundaries
- [ ] Confirm AR/EN/FR text and layout at 320/360/393/768/1440px
- [ ] Capture staging snapshots, release hashes, application/database backups and safe rollback procedure

## Manual and operational handoff

- [ ] Verify the existing staging endpoints: https://test.market575.com and https://api-test.market575.com
- [ ] Record any critical defect, owner, evidence, fix commit, retest and sign-off
- [ ] Do not publish to market575.com / api.market575.com without explicit release approval
- [ ] Manager demo includes verified flows and a separate list of unsupported or untested scenarios

These checks are intentionally unmarked until supported by test logs, screenshots and release approval.
