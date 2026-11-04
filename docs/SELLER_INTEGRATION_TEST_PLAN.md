# ALL-5.75 Seller Integration Test Plan (v2)

This checklist is for local/staging testing only. Do not deploy to production until tests and business acceptance pass.

## 1. Prepare

Backend (Windows PowerShell):

```powershell
cd C:\xampp\htdocs\all-575-api
git checkout v2
git pull origin v2
php artisan optimize:clear
php artisan migrate:status
php artisan test --filter=SellerStoreProfileTest
php artisan test --filter=SellerCatalogImportPreviewTest
php artisan test --filter=SellerApplicationDocumentTest
php artisan test --filter=SellerExtendedApplicationTest
php artisan test --filter=SellerWorkspaceTest
```

Frontend:

```powershell
cd C:\xampp\htdocs\all-575-android
git checkout v2
git pull origin v2
npm ci
npm run build
npm run dev
```

Use a dedicated test database and a test seller with an approved membership. Do not run destructive operations against production.

## 2. Customer-to-seller registration

- Register/sign in using email/password and a real email OTP in a safe test environment.
- Save Seller application: legal name, CR, optional 700/VAT, contact details and city.
- Fill and reload the extended optional Saudi fields: entity type, CR status/issue/expiry dates, Chamber of Commerce number, building, postal code, additional number, street, district and IBAN. Verify stored values and validation errors for invalid dates.
- Submit application for review. Check it cannot be modified while submitted.
- Return a sample application for correction using the authorized administrative process; verify the seller sees the review note, edits, and resubmits.
- Verify a newly registered unapproved seller cannot receive a Seller session token.
- Upload sample registration, VAT, national address and IBAN documents (PDF/JPG/PNG, max 10 MB) before submitting. Verify private metadata listing and that submitted applications reject further uploads.
- Confirm documents are stored on the Laravel **local/private disk**, never public product-image storage.
- **Not yet complete:** approved administrator download/review interface, expanded Saudi national address fields, document authenticity review and full back-office approval UI.

## 3. Seller ownership and store/branches

- Sign in as an approved Seller owner, open **Store & branches**.
- Change Arabic/English store display names and refresh.
- Change an existing branch's Arabic/English display names and refresh.
- Try whitespace-only names: both must be rejected.
- Attempt same actions as a different seller, picker, branch manager or inactive owner: expect forbidden/not found.
- Confirm Seller cannot update legal identity, approval status, branch active state, delivery zone or coordinates by injecting extra JSON fields.
- **Not yet complete:** creating branches, full legal/store profile, map service-zone editing and bank details.

## 4. Product management and CSV import

- Create a draft at SAR 1.00 and SAR 5.75; reject out-of-range values.
- Add a unique SKU and optional barcode; reject duplicates.
- Upload a valid cover image; reject files above 5MB and invalid formats.
- Submit for review; verify draft is no longer directly editable.
- Download CSV template; enter valid rows.
- Press **Validate & preview CSV**; confirm the first ten rows and total number are displayed.
- Check database unchanged after preview.
- Confirm import; verify only draft products were created.
- Upload malformed rows and duplicate SKUs; confirm errors and no partial import.
- Check product listing **Load more** with over 50 products.
- **Not yet complete:** native .xlsx upload, barcode scanning hardware integration, admin review UI and product gallery.

## 5. Orders, packing, inventory

- View orders by status, branch, and date range; with more than 50 records, use **Load more**.
- Confirm order CSV export follows those filters.
- Open packing list and compare product variants/quantities with the order.
- Advance order Awaiting seller -> Preparing -> Ready with authorized staff only.
- View inventory and adjust physical quantity; reject quantities below reserved stock and unauthorized branches. Verify **Load more** with over 50 stock rows.
- **Not yet complete:** full branch replenishment workflow and all delivery transitions.

## 6. Returns and staff

- View Seller returns queue; filter by status, load more.
- Send an initial accept or dispute position with explanation 10-2000 characters.
- Verify second seller response to same case is rejected. Confirm no refund can be issued from Seller UI.
- View staff as store owner; deactivate non-owner staff and verify access removal.
- Confirm owners cannot be deactivated through that endpoint.
- **Not yet complete:** full dispute adjudication and refund settlement integration.

## 7. Finance

- View sales summary and ledger by date as an approved owner.
- Verify branch managers and unrelated sellers cannot access owner financial data.
- Check settlement-readiness checklist (commission, refund allocation, finance approval) and confirm no transfer action exists.
- Verify merchandise totals are not labelled seller payout.
- **Not yet complete:** live payout execution, audited settlement approval and final reconciliation.

## 8. Release gate

Record pass/fail and screenshots for every item. Before production deployment, require:
- All build/test failures resolved.
- Backend migration review and database backup.
- Privacy/security review of document storage, staff permissions and financial endpoints.
- Verified mail provider and OTP flow.
- Business sign-off for commissions, refunds, settlements and admin approvals.
- Review the new seller_application_documents migration in staging before applying to production. Never run migrations on production without approval and backup.

Code committed to GitHub is not equivalent to tested, deployed or accepted functionality.
