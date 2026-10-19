# Hotel child-policy database migration

## Localhost application record

Applied on 20 October 2026 at 02:51 Asia/Manila to `localhost`, database `db_itourmercedes` (MySQL 8.0.40).

Created two additive tables:

- `hotel_room_child_policies`: explicit hotel-approved adult capacity, child allowance, total occupancy limit, approving user and approval timestamp. The existing room capacities are not expanded automatically.
- `hotel_booking_child_policies`: immutable child-policy version and JSON snapshot per new hotel booking, including child ages, applicable charges and approved occupancy limits.

Both tables were verified empty after creation. Existing room and booking rows were compared with SHA-256 hashes before/after and were unchanged. Booking count remained 7; booking total remained PHP 24,565.00; recorded paid total remained PHP 14,645.00. No existing booking, payment, capacity or refund was updated. No foreign keys or backfills were added.

A full logical local database backup (including routines, triggers and events) was completed before migration:
`storage/private/hotel-child-policy/localhost-before-child-policy-20261019-205130.sql` (449,595 bytes).
This backup contains private database data. Keep it private; do not commit it or upload it to the live web root.
Verification details: `storage/private/hotel-child-policy/localhost-migration-result.json`.

## Manual live database instructions

1. Export/back up the live database in phpMyAdmin first. Record the selected live database name.
2. Open `docs/hotel-child-policy-migration.sql`. In phpMyAdmin, select your live site's database, then use its SQL tab (or Import tab for this SQL file).
3. Run the PRECHECK statements first. Confirm this is the intended live database and that `hotel_rooms` and `hotel_room_bookings` exist. Save the booking count and financial totals. If either new table already exists, inspect its structure against the CREATE statements before proceeding; IF NOT EXISTS does not repair an incompatible schema.
4. Run both CREATE TABLE statements exactly as supplied. Do not change existing room capacity or historical bookings. There is no USE statement: phpMyAdmin's selected database determines the target. Do not use the localhost database name on live unless it actually matches your hosting database.
5. Run the POSTCHECK statements. Verify the definitions, row counts and unchanged booking count/financial totals. Tables start empty on first application; do not empty them if they already contain policy records.
6. Apply the matching hotel child-policy application release only after staging verification. SQL alone does not change the visible age note, room eligibility or pricing. The implementation package is in `storage/private/hotel-child-policy/release/files/`; its review documentation remains applicable. The database task did not replace application files. The subsequent localhost activation is recorded below.
7. Approve room occupancy explicitly through the updated hotel room settings. Creating these tables does not itself authorize additional child occupancy.

The SQL uses InnoDB and utf8mb4 without a MySQL-8-specific collation so Hostinger MySQL/MariaDB can select its own supported default. It adds only the two tables and is rerunnable for matching existing schemas.

## Recovery

If application rollback is required, stop new hotel submissions first. Retain the additive tables and all saved policy snapshots. Do not drop the tables, reprice paid reservations or restore a backup over newer live payments without reconciliation. After new-policy bookings exist, use a recovery application version that can still read their saved snapshots.

## Scope

The initial database task applied localhost database changes only. No live database changes, website deployment, commit or push were performed. The previously documented late-payment-after-expired-hold limitation still requires separate staging/operational review.


## Subsequent localhost application activation

At the user's request, the matching hotel-child-v1 implementation was activated in the main localhost application on 20 October 2026 (Asia/Manila). All 24 application/test files matched the reviewed baseline before replacement; backups were saved under `storage/private/hotel-child-policy/localhost-app-before-2026-10-19T18-57-35-144Z`. Eleven protected payment/refund/settlement/manifest/tour/catalog source files remained unchanged.

Verified against the active project: PHP/JavaScript syntax and all 17 regression suites passed. Actual Apache HTTP responses from hotel_resorts.php and hotel_details.php?id=321 serve the new note without the old wording. Room availability for hotel 321, room 25, 20-22 November 2026 quotes PHP 0 for age 5 and PHP 500 for age 6 or 17 (two nights). Missing ages and age 18 return HTTP 422. No booking or payment was submitted during these HTTP checks.

Records: `storage/private/hotel-child-policy/localhost-application-result.json`, `localhost-application-tests.log`, and `localhost-http-smoke.json`. Existing room limits are preserved until explicit hotel approval. This local activation has not been deployed to live, committed or pushed. Full browser booking/payment and actual competing MySQL-session checks remain staging work.
