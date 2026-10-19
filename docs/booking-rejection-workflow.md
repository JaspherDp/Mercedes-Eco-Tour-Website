# Booking rejection, notification and refund queue

Implemented locally on 20 October 2026 for main admin, tour operator and hotel admin. No live deployment, commit, push, historical rejection backfill, actual rejection of customer bookings, real email, PayMongo operation or refund transfer was performed during development.

## Behaviour

- Main admin Decline, operator Cancel on a pending booking, and hotel Cancel now use one authorized rejection workflow. Hotel cancellations are allowed for pending/confirmed reservations before check-in; check-in/out reservations continue through operational checkout instead.
- A reason is required server-side (1-1,500 characters). Existing admin category/detail selections are retained. Operator/hotel dialogs collect the reason, with a browser prompt fallback.
- Booking cancellation and the corresponding cancellation request commit in one transaction. For tours, status=accepted and is_complete=cancelled intentionally match the pre-existing provider-cancellation convention. Hotel booking_status becomes cancelled. The recorded rejection reason and audit action identify the decision.
- Cancellation requests use initiated_by=admin_provider, request_status=approved, tourist_decision=booking_rejected. Main-admin reviewer ID is stored only for the main-admin role; operator/hotel ownership is recorded in admin_note and the activity log, avoiding ID collisions between roles.
- Refund eligibility is the full recorded amount paid (provider_full_refund), without the tourist late-cancellation deduction. refund_status=pending when paid; no_payment/not_applicable when unpaid. The request appears in existing cancellation/refund queues. No money is transferred automatically.
- Booking prices, paid amounts, remaining balances, payment transactions, payout accounting and actual refund transfer controls are unchanged.
- A branded rejection email includes booking, service/date, full reason, approved cancellation status, amount paid, eligible refund and pending/no-payment status. Registered tourist addresses and stored unlinked guest addresses are supported. Email follows commit; failures leave cancellation/refund approval intact and show a warning. Main-admin Cancellation Requests > Resend Tourist Email supports this rejection notice.
- A repeated completed rejection returns the existing request without creating another request or automatically resending an email. The existing unique booking cancellation key and booking-row locks are preserved.

## Safeguards

Authentication and existing per-panel CSRF scopes are checked. Operators can reject only their assigned pending bookings; hotel admins only their property. Accepted tour bookings use the existing provider-cancellation workflow instead. An active pending checkout must be finished or safely cancelled before rejection, so another payment cannot arrive against the captured refund amount. Existing approved/completed requests and refund attempts are protected from overwrite. Pending tourist cancellation requests can be upgraded to provider-full-refund without clearing saved refund destination details.

## Database/deployment

No new database columns/tables or schema migration are required on the inspected localhost database. It already has the cancellation/provider-workflow schema and booking_refunds/payment_transactions tables. The code does not run schema changes. `docs/booking-rejection-precheck.sql` contains read-only checks for the live database; confirm the existing columns and unique key before deploying this code. If hosting is missing earlier provider-cancellation migrations, reconcile its schema with the existing migration files first; do not run unrelated SQL blindly.

New application files: php/booking_rejection_helper.php and js/booking-rejection.js. Changed: admin/adbookings.php, operator/opbookings.php, Hobookings.php, php/provider_cancellation_email.php. Earlier unrelated payment fixes remain in those files. Original versions for this task are in storage/private/booking-rejection-fix/before/.

## Validation

- 181 SQLite fixture assertions: all roles, unpaid/deposit/full-paid reservations, full refunds, saved reasons, duplicate posts, recipient fallback, ownership, checked-in protection, active-payment protection, existing request/destination handling, refund-attempt protection, email failure and atomic rollback.
- Shared dialog tests: reason required/length, safe quoted/HTML input, cancellation, repeat submits and prompt fallback.
- Actual local MySQL definitions/transactions verified with connection-local temporary tables only: tour/hotel cancellation, approved full-refund records and repeat requests. SHA-256 comparison of permanent booking/cancellation/payment/refund rows before/after was unchanged.
- Existing refund routing/critical refund safeguards, notification, operator/walk-in payment/acceptance and hotel child-policy UI suites passed. Notification fixtures intentionally exercise failure logging; no production email or payment was used.
- PHP and JavaScript syntax checks passed. Full authenticated three-panel browser operation and SMTP delivery remain staging verification; SMTP success is reported only when the configured sender accepts the message, not a guarantee of inbox delivery.

Tests: scripts/booking_rejection_test.php, scripts/booking_rejection_ui_test.cjs, scripts/booking_rejection_mysql_test.php. The MySQL test refuses non-loopback database hosts and uses temporary tables without triggers/foreign keys; no production records are modified.

## Tourist hotel cancellation review ownership update

The subsequent hotel cancellation review feature is documented in `docs/hotel-cancellation-review.md`. Tourist-origin hotel cancellation requests are now approved/rejected by their property's hotel admin, with main-admin decision controls blocked server-side. Main admin still processes the refund after hotel approval. Provider rejection behaviour described above is unchanged.

## Rejection/cancellation email progress feedback

The follow-up UI update adds a blocking SweetAlert loading indicator before main-admin rejection, operator/hotel rejection or cancellation, and hotel cancellation-review submissions. It allows the spinner to paint before submitting the form, prevents dismissal during processing, and disables submit buttons. Rejection and hotel-review result dialogs explicitly use a green OK button. Shared script versions were incremented to v2. Existing provider cancellation and approval sending spinners remain in place. No backend, database, email delivery or refund logic changed.

Verified using shared rejection/review dialog tests and scripts/admin_rejection_spinner_test.cjs, including spinner-before-POST order, preserved reason/CSRF values and green result-button options. PHP and JavaScript syntax checks passed. No real emails were sent during verification.
