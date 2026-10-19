# Hotel-owned tourist cancellation review

Implemented locally on 20 October 2026.

## Workflow and permissions

1. A tourist submits a hotel/room cancellation from the profile. The existing request remains pending; the reservation stays active until review.
2. `Hobookings.php?tab=cancellations` shows that property's cancellation requests, pending count, filters/search, pagination, guest/room/stay details, reason, refund eligibility and live database refund status.
3. Only that property's authenticated hotel administrator can approve/reject a pending tourist-origin request. Rejection requires a reason, records the decision and keeps the reservation active. Approval cancels the reservation and sets eligible refunds to pending (or not_applicable when no refund is due).
4. Main Admin > Cancellation Requests retains visibility. Pending hotel requests show Awaiting hotel review without Approve/Reject buttons. The POST handler independently rejects main-admin approval/rejection of any hotel request, including forged requests or shared browser sessions.
5. Once approved by the hotel, the request appears in the existing main-admin refund queue. Main admin keeps the actual refund-processing controls and existing refund-completion safeguards. The hotel list has no refund processing controls and displays current refund status from the same cancellation record on reload/Refresh.
6. Approval/rejection sends a guest decision email after commit; email failure displays a warning and does not undo the saved review. Hotel cancellation/refund notification links open the new tab.

Provider/admin rejection remains the separate auto-approved full-refund workflow added earlier. This change moves tourist hotel cancellation review to the hotel; tour cancellation review remains with main admin.

## Policy and safety

- Property ownership is enforced in both listing and transactional decision queries; request/booking tourist IDs must agree.
- Lock order is booking then request. Repeat identical decisions are idempotent; opposite/stale decisions fail.
- The refund is calculated using the original tourist request date, not the later hotel review date. Actual recorded paid/total amounts are refreshed under lock to avoid a stale amount. The existing tourist policy remains: 3+ days full amount paid; less than 3 days retain 20% of booking total, limited by amount already paid. Deposit-only late cancellation yields no refund.
- Checked-in/out or otherwise ineligible bookings cannot be approved for pre-arrival cancellation. Active pending checkouts must be completed or safely cancelled before approval.
- Booking prices, paid amounts and existing payment/refund/payout records are not rewritten by review. Refund transfers remain in main admin's existing workflow.
- Hotel reviewer attribution is recorded in the existing Hotel Owner activity log with cancellation request/booking references; reviewed_at is saved and reviewed_by_admin_id remains NULL for hotel decisions to avoid mixing IDs from different admin roles.

## Database and deployment

No schema migration or manual SQL change is needed for this update on the inspected localhost schema. It reuses booking_cancellation_requests, hotel_room_bookings, payment_transactions and existing activity/notification tables. Existing pending hotel requests become available to their property's hotel admin; no historical data is backfilled or auto-approved.

Application files: Hobookings.php, admin/adbookings.php, php/notification_helper.php; new php/hotel_cancellation_review.php, php/hotel_cancellation_requests_view.php, styles/hotel-cancellation-requests.css and js/hotel-cancellation-requests.js. Backups: storage/private/hotel-cancellation-review/before/.

No live deployment, commit/push, real guest email, actual customer cancellation or refund transfer was performed during development.

## Verification

- 65 SQLite assertions covering full/partial/deposit-only/unpaid policies, original request date, current paid amount, ownership, active payments, checked-in protection, transaction rollback, idempotency, email failure, list filtering, main-admin restrictions and readonly live refund status rendering.
- Shared review dialog fixture: mandatory reason, length limits, safe form values, approval confirmation, duplicate submits and cancelled dialogs.
- Actual localhost MySQL schema verified through connection-local temporary tables: hotel approval, scoped list, main-admin decision restriction and changed refund status visibility. Permanent booking/cancellation/payment/refund rows compared by SHA-256 before/after and unchanged.
- Rejection (181 checks), refund routing/critical safeguards, notifications, hotel child-policy UI, operator payment flow and walk-in payment flow passed. PHP/JS syntax checks passed. Failure logs in refund/notification suites are intentional fixture paths.
- Tests: scripts/hotel_cancellation_review_test.php, scripts/hotel_cancellation_review_ui_test.cjs, scripts/hotel_cancellation_review_mysql_test.php. No actual provider, SMTP delivery or authenticated full-browser workflow was exercised; those remain staging checks.

## Hotel cancellation tab layout update

- Matched the main admin layout with summary cards, aligned status badges, and a red pending-count capsule on the Cancellation Requests tab. Removed the introductory sentence above the filters.
- Moved Approve, Reject and View reason into each row's Actions menu. Reasons and review notes open in a text-only modal. The menu opens outside the scrolling table to prevent clipping and closes on Escape, outside click, scrolling or resizing.
- Added a property-scoped, read-only summary query. Existing hotel review permissions and main admin refund processing remain unchanged; no database migration is needed.
- Verified menu positioning, keyboard dismissal, safe reason rendering, review dialogs, all 65 cancellation service checks, localhost MySQL temporary-table checks and PHP syntax. No real email, cancellation, refund or permanent database change was performed. Browser visual verification remains outstanding.
- Additional test: scripts/hotel_cancellation_actions_test.cjs. Layout backups: storage/private/hotel-cancellation-layout/before/.
