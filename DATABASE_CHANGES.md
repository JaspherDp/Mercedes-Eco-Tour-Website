# Database changes — pending Hostinger update

Recorded: 17 October 2026 (Asia/Manila).
Feature: booking add-ons and receipts, multi-boat/multi-guide capacity, guest classification, and admin service management.

**Hostinger status: PENDING. No hosted database changes or real payments were performed by this work.**

Localhost and Hostinger are separate databases. Changing localhost does not update Hostinger. Uploading website files does not import a local database or migrate hosted records.

## SQL to run later

After backing up the hosted database, import [deployment_2026_10_17_migration.sql](deployment_2026_10_17_migration.sql) into the correct Hostinger website database before deploying the matching code. This combined file includes the catalog, expense-column, and resource-allocation migrations; do not also import the individual files.

It creates missing booking_addon_catalog, booking_resource_boats, booking_resource_guides, and booking_walkin_details tables, and conditionally adds expense_name, quantity, and unit_price to the existing booking_expenses table. All changes are additive. Existing rows, historical prices, credentials, and account IDs are preserved. Re-running skips existing objects; it does not repair an incompatible existing definition.

The age rules (adults 14+, children 0–13), environmental-fee exemption, and admin navigation changes need no additional columns or data updates. Existing adult/child counts remain in use; both count toward capacity. The assigned-resource tables use booking_id with boat_id or guide_id and unique composite primary keys.

Locally, the catalog table was initialized during settings-page verification. The three expense itemization columns were already present when inspected. Hostinger has not been inspected, so its current schema must be checked at deployment time.

## New table: booking_addon_catalog

| Column | Definition | Purpose |
| --- | --- | --- |
| addon_id | INT UNSIGNED, AUTO_INCREMENT, primary key | Add-on identifier |
| owner_role | VARCHAR(20) NOT NULL | admin, operator, or hotel_admin |
| owner_id | INT UNSIGNED NOT NULL | Creating account ID |
| hotel_resort_id | INT UNSIGNED NULL | Property ID for hotel extras; NULL for tour extras |
| name | VARCHAR(160) NOT NULL | Add-on label |
| price | DECIMAL(10,2) NOT NULL | PHP price per item, once per booking |
| is_active | TINYINT(1) NOT NULL DEFAULT 1 | Available or hidden |
| created_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | Creation time |
| updated_at | TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Last edit time |

Indexes: addon_owner(owner_role, owner_id), addon_availability(is_active, owner_role, hotel_resort_id). Storage: InnoDB, utf8mb4, utf8mb4_unicode_ci. No foreign keys, triggers, or seed rows are introduced. The application enforces roles, ownership, eligibility, quantities, and valid prices.

Admin options appear on boat, guide, and tour package bookings. Operator options appear only on tour package bookings. Hotel administrator options appear only on that account's hotel/resort room bookings. Each account manages its own entries. Hidden options cannot be selected in new checkouts.

## Existing table: booking_expenses

The companion migration adds the following only if missing:

| Column | Definition |
| --- | --- |
| expense_name | VARCHAR(160) NULL |
| quantity | INT UNSIGNED NULL |
| unit_price | DECIMAL(12,2) NULL |

Existing booking_id, expense_type, amount, note, and the normal row identifier remain in use. Selected tourist tour extras are saved as separate expense rows with expense_type = others and note = Selected during booking. No historical rows are backfilled or recalculated.

## Existing tables reused without new columns

- booking_checkout_drafts: the existing payload JSON gains catalog_addons with rows and total. Each row saves addon_id, name, quantity, unit_price, and amount. Existing total_minor and amount_minor include selected extras. This changes JSON content, not the table schema.
- bookings: existing grand_total, payment_amount, remaining_balance, and is_paid represent the combined booking and extras.
- hotel_room_bookings: existing total_amount, amount_paid, remaining_balance, and payment_status represent the room booking and extras.
- hotel_booking_expenses: existing hotel_booking_id, hotel_resort_id, expense_type, expense_name, quantity, unit_price, total_amount, notes, and payment_status store hotel extras. These columns were confirmed locally; check them on Hostinger. If any are missing there, stop and resolve that pre-existing hotel expense schema dependency before enabling add-ons. Do not replace hosted records with a local table export.
- payment_transactions: existing amount and reference fields remain in use; no columns or connection settings change.
- The payment-success Receipt button, print design, and PDF download require no additional database changes.

Extras are included once in the booking total before calculating full payment or a 20% deposit. Verified paid-draft finalization records the same extras as expense rows within the existing transaction and duplicate-payment guard, without increasing totals again. Receipts exclude separately listed extras from the base service charge. Saved draft names/prices and existing expense rows remain unchanged when catalog entries are edited or hidden.

## Hostinger deployment procedure — do later

This is a plan, not a record of completed remote work.

1. Schedule the update. Back up the hosted database and deployed files. Keep the original deployment available.
2. Open Hostinger database tools/phpMyAdmin and select the website's actual database. Confirm it with SELECT DATABASE();
3. Inspect SHOW COLUMNS FROM booking_expenses; and SHOW COLUMNS FROM hotel_booking_expenses;. Confirm the established booking_checkout_drafts, bookings, hotel_room_bookings, and payment_transactions tables exist. This feature assumes the current payment system migrations are already installed.
4. Import deployment_2026_10_17_migration.sql. If anything fails, stop and inspect the error. Do not import the entire localhost database over hosted records.
5. Verify SHOW CREATE TABLE booking_addon_catalog;, SHOW CREATE TABLE booking_resource_boats;, SHOW CREATE TABLE booking_resource_guides;, SHOW CREATE TABLE booking_walkin_details;, and SHOW COLUMNS FROM booking_expenses;. SELECT COUNT(*) AS catalog_entries FROM booking_addon_catalog; should show zero for a fresh catalog; existing catalogs retain their rows.
6. Deploy the matching PHP/JS/CSS together, including checkout creation, paid-draft finalization, and receipt breakdown changes. See [payments/ADDON_CATALOG.md](payments/ADDON_CATALOG.md).
7. Create admin options through Services > Pricing & Add-ons > Add-ons. Operator and hotel administrator options remain in their System Settings > Add-ons tabs. Do not copy localhost account IDs or catalog rows without explicitly mapping accounts and hotel IDs to the hosted database.
8. Check owner isolation, hotel-only visibility, quantities/prices, review totals, deposits, and receipt rows in a non-production/test setup first. Any real charge is a separate action and is not part of applying the SQL.

## Automatic schema setup in the application

Opening the new settings panel can create booking_addon_catalog on the server where the code runs, if it is missing and CREATE privileges exist. Tour checkout with selected extras can also ensure the three expense itemization columns before taking payment. This is application behavior, not synchronization from localhost.

The hosted update remains deferred. Prefer the explicit SQL procedure above at the scheduled deployment, before deploying/opening the new hosted feature. The combined migration file is prepared for manual import; it has not been executed on Hostinger.

## Recovery and historical data

The additive table/columns can remain if deployment is postponed. No destructive rollback SQL is provided. Once a booking or pending checkout uses catalog_addons, retain a finalizer version that understands those saved selections until pending checkouts are resolved. Reverting only the finalizer could omit expense rows even though the paid booking total includes extras. Preserve payment, draft, and expense records during diagnosis; do not drop related columns or tables while in use.

## Local verification already performed

Offline fixtures cover catalog ownership, hotel-property and booking-type isolation, server prices, quantities, hidden options, saved prices, full/deposit payments for all four booking types, receipt math, duplicate events, and transaction rollback on expense-save failure. Existing settings and payment-return checks passed. No hosted migration or real PayMongo charge was performed. Browser visual verification was unavailable.

## Visibility correction — 17 October 2026

Operator-created options are now restricted to tour package bookings in both the form picker and server checkout validation. Admin options remain available for boat, tour guide, and tour package bookings. Hotel options remain limited to the creating account's hotel/resort room bookings. Changing the tour booking type hides and clears ineligible operator selections. This correction requires no additional database columns, migration, or data updates. Previously saved checkout prices and historical bookings are preserved. Hostinger deployment remains pending.

## Tourist inactivity correction — 17 October 2026

No database schema or saved timeout change is required. The correction updates PHP session activity tracking and uses the existing tourist timeout setting. Local duration remains 60 minutes. See [TOURIST_SESSION_INACTIVITY.md](TOURIST_SESSION_INACTIVITY.md) for behavior, deployment files, and tests. Hostinger remains unchanged; deploy the related code together when scheduled.
