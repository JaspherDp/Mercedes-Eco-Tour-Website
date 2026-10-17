# Booking add-on catalog

System Settings > Add-ons is available to administrators, operators, and hotel administrators. Each account manages its own entries. Admin entries are offered on boat, tour guide, and tour package forms. Operator entries are offered only on tour package forms. Hotel entries are offered only for the creating administrator's hotel/resort.

Prices are per item per booking. Tourists select optional items and quantities (1–100, maximum 25 distinct entries). Prices, names, and eligibility are resolved by the checkout server; client prices are never trusted. Hidden options cannot be selected in new checkouts. Paid drafts retain the original selections and prices.

## Installation

Hostinger deployment is pending. See [DATABASE_CHANGES.md](../DATABASE_CHANGES.md) for database changes, SQL order, prerequisite checks, and the deferred deployment procedure. Localhost changes do not synchronize to Hostinger.

Deploy the changed PHP/JS/CSS files together, including the helper and paid-draft finalizer changes. The additive root migration booking_addon_catalog_migration.sql creates the catalog; booking_addon_expense_columns_migration.sql conditionally adds missing tour expense itemization columns. Settings initializes the table automatically if it is missing and the database user has CREATE permission. Otherwise apply the migration with the normal database deployment process. No sample options are seeded.

Existing booking_expenses and hotel_booking_expenses tables remain in use. Tour expense-name/quantity/unit-price columns are ensured before payment creation if necessary; hotel expense columns are checked before a checkout with add-ons can proceed. Public forms continue working without selections if the catalog has not been installed.

## Accounting

Selected extras are included once in the authoritative checkout total and the 20% deposit calculation. Verified paid draft finalization records the same extras as itemized booking expenses within the existing payment transaction and idempotency guard. It does not increase totals a second time. Receipt base fare calculations subtract the catalog extras before displaying the expense rows. Existing PayMongo endpoints, credentials, redirects, and payment methods are unchanged.

## Verification

- php scripts/booking_addon_catalog_test.php — offline catalog scope, validation, immutable prices, full/deposit payments for all booking types, receipt math, atomic rollback, and duplicate events; includes the existing 222 PayMongo mode checks.
- node scripts/booking_addon_picker_test.cjs — selection, quantities, totals, review rows, deselection, and limits.
- php scripts/portal_settings_render_test.php — actual local GET routes, add-on editor, independent form controls, and existing settings.
- php scripts/booking_success_receipt_test.php — receipt totals and access scope.
- node scripts/paymongo_return_browser_test.js — existing 80 payment-return checks.

No real PayMongo payment is created by these tests. Visual browser verification requires a connected browser.
