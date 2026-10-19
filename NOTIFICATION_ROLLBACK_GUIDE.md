# Notification rollback guide

Scope: this enhancement only. No production deployment or live SQL was performed by the implementation agent.

## Before release

Save the actual working production source, database export, release identifier and deployment configuration outside the public document root. Save uncommitted production changes too; a Git commit alone does not capture them. Record which notification files were deployed and whether the manual SQL succeeded. Keep the release before this change available.

The development workspace already contained unrelated booking, hotel manifest, add-on and styling edits. Do not use a blanket `git reset --hard`, `git checkout .`, or directory replacement against that workspace to undo this task. Review the notification-specific file list in `NOTIFICATION_IMPLEMENTATION_REPORT.md`; revert only these changes with contextual review of shared files, especially `admin/adbookings.php`, `php/profile.php`, and `public/search_results.php`.

## Normal production rollback

1. If the site is encountering errors, use the existing maintenance procedure to pause new writes while switching releases. Preserve current PHP logs and note the failure time without exposing credentials or customer data.
2. Restore the backed-up previous source release as a coherent set. Restore PHP entry points and their matching old assets/helpers together, or atomically switch to the saved release directory. Do not leave PHP calls to removed notification helpers.
3. Clear the relevant Hostinger/CDN/browser assets and PHP opcode cache through the normal hosting controls. Confirm the restored release is served.
4. **Leave `app_notifications` intact.** Old code ignores it. No old table, column, status, foreign key, identifier, email template, payment webhook or settlement rule was changed by the notification schema SQL.
5. Confirm the homepage and all four panels load. Verify legacy main-admin booking links, tourist submission/email links, provider booking scope, billing and the existing payment status interfaces. Reopen writes after these checks succeed.

The extra table, uniqueness key and indexes are additive and may safely remain indefinitely. Notifications created while the new code was active remain available if the enhancement is redeployed later. Read-state changes in the new table do not rewrite the old `bookings.is_notif_viewed` flag or session read markers, so a restored legacy panel can show a different unread count. That is a notification-state difference, not a booking or payment-state change.

## SQL interruption or failed deployment

MySQL DDL can commit independently of the import. If SQL stopped partway through, retain the table and inspect `SHOW CREATE TABLE app_notifications` and its indexes. The supplied history imports are repeatable and do not reset read states on duplicates. Resolve the specific error before rerunning; do not guess at a conflicting schema or remove structures to make an import succeed.

If the new source is already present but the notification table is absent/unreadable, schema guards keep the legacy notification interface available. This does not protect against incomplete file uploads: restore the old release or finish the verified atomic upload if a referenced helper is missing.

## Database restoration only when data recovery is actually required

No database restoration is needed to undo this additive enhancement. Blindly restoring a predeployment backup can erase bookings, successful payments, refunds, submissions and settlements created after the backup.

If genuine database damage occurs: pause writes, export the current database, restore the older backup into a separate recovery database, compare both versions, reconcile post-backup transactions using existing provider/audit records, and obtain the owner's explicit approval before any targeted production recovery. Verify the correct website/database in hPanel. Do not run destructive SQL or delete notification tables/columns holding production records as a shortcut.

The notification SQL contains no destructive rollback statements. Schema removal would require a separate retention/export and owner-approved migration after confirming no deployed code still uses it; that action is outside this task.

## Local activation follow-up ? 2026-10-19

After the initial isolated tests, the owner reported missing localhost alerts. The notification table was confirmed absent, then the supplied additive SQL was applied to the verified local database only, creating the table/indexes and importing 43 history entries. The 149 backend and 66 UI fixture assertions passed again using isolated fixtures. No email was sent by the agent. Earlier assertions about no permanent local schema changes describe the initial test phase; the localhost migration is now applied. Hostinger remains untouched.
