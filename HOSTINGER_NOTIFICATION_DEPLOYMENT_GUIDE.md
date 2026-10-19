# Manual Hostinger notification deployment

Prepared 2026-10-19. **Do not deploy until the owner approves.** No Hostinger database was accessed or changed during implementation. Git deployment does not apply SQL.

## 1. Before deployment

1. Back up the entire live database and deployed source. Record the current release/commit and save any uncommitted deployed files separately. Keep backups outside the public document root. Confirm the database backup can be restored to an isolated database.
2. Finish the staging checklist in `NOTIFICATION_TEST_RESULTS.md`, especially rendered desktop/mobile views, authenticated links and test-mode payments. The automated fixtures are not a live deployment certification.
3. In hPanel, select the correct website and its **Databases → phpMyAdmin** entry. Open the database used by that site's existing private configuration; do not change credentials. Confirm the selected database name locally, without sharing credentials.
4. Use the SQL tab for these read-only checks:

```sql
SELECT DATABASE() AS selected_database, VERSION() AS database_version;
SHOW TABLES LIKE 'app_notifications';
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('bookings','hotel_room_bookings','booking_cancellation_requests',
                    'admin_users','operators','hotel_admin_accounts')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
```

The local schema was MySQL 8.0.40. Production's engine/version has not been verified. The SQL uses portable `CREATE TABLE IF NOT EXISTS`, `SHA2`, and `ON DUPLICATE KEY UPDATE`; no version-dependent `ADD COLUMN IF NOT EXISTS`. It was executed on local MySQL 8.0.40 temporary fixtures, not MariaDB. Use the same PHP version already required by this application; local CLI checks used PHP 8.0.30. No new dependencies or credentials are needed.

Verify these existing columns before importing:

| Table | Columns needed by history migration |
|---|---|
| `bookings` | `booking_id`, `operator_id`, `created_at`, `is_notif_viewed`, `preferred_resource`, `status`, `is_complete`, `is_paid`, `grand_total`, `remaining_balance`, `payment_amount` |
| `booking_cancellation_requests` | `booking_domain`, `booking_id`, `tourist_decision`, `tourist_responded_at` |
| `admin_users` | `admin_id` |
| `operators` | `operator_id`, `status` |
| `hotel_room_bookings` | `hotel_booking_id`, `hotel_resort_id`, `created_at` |
| `hotel_admin_accounts` | `hotel_admin_id`, `hotel_resort_id`, `status` |

The code also uses existing booking ownership/reference/status fields, `booking_tourists`, `hotel_booking_tourists`, and payment transaction fields identified in the implementation report. Some of these come from pre-existing uncommitted hotel work; this notification SQL does not create those structures. If production differs, stop and reconcile the existing migrations before this release. Do not assume local-only hotel features already exist on Hostinger.

If `app_notifications` already exists, run `SHOW CREATE TABLE app_notifications` and `SHOW INDEX FROM app_notifications`, then compare every column, default and key to the supplied file. `IF NOT EXISTS` will not repair an incompatible table. Stop on a conflict; do not rename, overwrite or delete an existing table.

Creating the new table does not require altering or stopping the booking tables. For a consistent notification cutover, arrange a brief low-traffic write pause using the site's existing maintenance procedure while SQL/backfill and the release are applied. Without a pause, actions completed by old code during the cutover will not emit the new events. Large history backfills should first be timed on staging; `INSERT ... SELECT` can temporarily lock source reads depending on database isolation.

## 2. SQL execution — manually, database first

In the selected database, choose **Import**, select `HOSTINGER_NOTIFICATION_DATABASE_UPDATE.sql`, keep SQL format, and execute. Alternatively paste its entire contents into the SQL tab. Follow its file order:

1. Create `app_notifications`, including its primary key, event uniqueness key and three secondary indexes.
2. Import the existing main-admin booking notification history, preserving `bookings.is_notif_viewed`. Existing tourist reschedule/cancellation responses retain meaningful titles and destinations.
3. Import assigned-operator history.
4. Import assigned-hotel-admin history.
5. Run the included `SHOW CREATE TABLE`, `SHOW INDEX`, and count checks.

Provider history starts **read**, because its old read markers were session-only and cannot be reconstructed accurately. New provider events start unread. Tourist browser-local messages are not imported: browser storage is not a reliable server-side ownership source. No bookings, payments, guest lists, old activity logs or email records are modified.

All statements can be rerun against the correct compatible schema. Repeating a backfill does not reset `read_at` or duplicate its submission entry. No notification schema migration or history backfill is scheduled or executed by PHP. The table is mandatory to enable the enhanced inbox; if absent, the application continues using its legacy bells and skips new event writes.

If any statement fails, record the exact database error privately and stop the release. DDL may already have committed; an import is not an all-or-nothing transaction. Fix only after identifying the schema/version/permission conflict. Once resolved, rerun the supplied repeatable file. Never respond to a failure by deleting production structures.

## 3. Code deployment

After SQL succeeds and staging checks pass, deploy the complete reviewed notification changes together with their compatible existing project baseline. Use the changed-file list in `NOTIFICATION_IMPLEMENTATION_REPORT.md`; do not deploy an arbitrary dirty working tree. The workspace already contained separate booking/guest-list changes, and these must be reviewed as a separate dependency decision.

Upload all new helpers, CSS and JavaScript before switching PHP entry points, or use an atomic release-directory switch. Deploying PHP that references files still missing from the server can fail even with a compatible database. Keep the old release available for rollback. No cron job, webhook URL change, PayMongo setting, settlement configuration, environment-variable change or Composer install is required.

Clear the Hostinger/LiteSpeed/CDN cache for changed PHP pages and JavaScript/CSS after the release. Use the hosting panel's normal cache controls; do not delete runtime uploads or private storage. Header assets use updated versions/file modification times. Check the response really contains the new inbox assets before reopening writes.

If writes were not paused, rerun the history inserts/file once after deploying to capture bookings created during the gap. This cannot reconstruct every transient status/reminder/payment event from that interval; inspect activity records and perform normal operational follow-up where needed.

## 4. Production verification

Use dedicated test accounts and approved synthetic bookings. Never pay using real customer bookings or live customer funds. Test PayMongo only in an isolated staging environment with test credentials.

Confirm the homepage, login and every panel load without PHP/database/JavaScript errors. Verify the existing submission, confirmation, cancellation, manifest, billing and settlement interfaces are intact. Check existing SMTP delivery and email form links separately; no real email was sent by the automated tests.

For each of the four roles: create an authorized test event, refresh/navigate/relogin, inspect the badge, click its item and verify the exact booking/form/status. Mark an item and all loaded history read; reload and verify persistence. Verify new arrivals remain unread when marking an earlier snapshot. Use a second tourist, hotel and operator to attempt access to the first account's notification ID and booking; expect denial. Verify a stale information-request item opens current booking details after submission.

For main admin, verify old booking notifications and cancellation-request links still work. For hotel and operator, verify current assignment scopes and existing details/PDF interfaces. For tourists, verify completed/rejected bookings open History, payment items open existing billing, and rescheduling items open the existing decision interface. Test desktop and mobile dropdown width/scrolling, keyboard Escape, loading/error/empty states and one badge per header.

Check stored records without exposing sensitive data:

```sql
SHOW CREATE TABLE app_notifications;
SHOW INDEX FROM app_notifications;
SELECT recipient_role,COUNT(*) AS total,SUM(read_at IS NULL) AS unread
FROM app_notifications GROUP BY recipient_role;
SELECT notification_id,recipient_role,recipient_id,event_type,booking_domain,
       booking_id,created_at,read_at
FROM app_notifications ORDER BY notification_id DESC LIMIT 20;
SELECT recipient_role,recipient_id,event_key,COUNT(*) AS copies
FROM app_notifications GROUP BY recipient_role,recipient_id,event_key
HAVING COUNT(*)>1;
```

The last query should return no rows. Inspect only records belonging to test accounts when checking destinations. Watch existing PHP logs for notification failures and SQL errors. DevTools should show one inbox fetch per visible page at startup and at most every 60 seconds, plus a throttled fetch when opened/visibility returns; hidden pages skip polling. Existing unrelated dashboard counters retain their original intervals.

## 5. Rollback

Restore the saved previous code release as a unit, clear relevant caches, and verify its booking bells, emails and payment endpoints. Leave `app_notifications` and its records intact: old code ignores this additive table. There are no business-column or constraint changes to reverse.

Do not restore an old full database backup over new production bookings/payments simply to undo notifications. If actual data recovery is needed, first pause writes, export the current database, restore the backup to a separate recovery database, reconcile all later transactions and obtain owner approval for a targeted recovery. See `NOTIFICATION_ROLLBACK_GUIDE.md`.

## Local versus Hostinger state

| Database change | Local persistent database | Hostinger live database |
|---|---|---|
| `app_notifications` table and indexes | Applied locally on 2026-10-19; temporary-table tests also passed | Pending manual SQL |
| Main-admin/provider history backfills | Applied locally: history imports completed | Pending manual SQL |
| Existing booking/payment/manifest schema or constraints | No changes by this task | No changes authorized |

Hostinger's current backup/export and database-import instructions: [Export a database with phpMyAdmin](https://www.hostinger.com/support/4529011-how-to-export-a-database-with-phpmyadmin-in-hostinger/), [Upload and set up a database](https://support.hostinger.com/en/articles/1864324-how-to-upload-and-set-up-your-database-at-hostinger). Use these only to locate the relevant hPanel tools; apply this release's additive SQL, not a replacement database dump.

## Local activation follow-up ? 2026-10-19

The first handoff left the persistent localhost migration pending. This caused successful tourist submission emails to send while the schema guard disabled the in-app inbox. After the owner reported this, the supplied additive SQL was applied to the verified loopback-only local database: `app_notifications`, all indexes and the three history imports now exist locally (43 history rows at activation). No existing business tables were altered. The 149 backend and 66 UI fixture assertions passed again. Earlier reminder sends while the table was absent were not stored as information-request notifications; refresh the administrator and tourist pages and resend through the existing action to create one. Hostinger SQL and deployment remain pending owner control.
