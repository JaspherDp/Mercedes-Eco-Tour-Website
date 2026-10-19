# Admin payment collection bug fixes

Applied to localhost source on 20 October 2026. No migration, data cleanup, real checkout creation, payment, refund, notification, commit, push or live deployment was performed.

## Modal amount

`js/adpaymenttransactions.js` previously kept the old amount whenever it was positive and below the next booking's outstanding balance. Thus TG26-0008's PHP 2,864 persisted when opening TB26-0007 with PHP 3,096 due.

Opening the modal now explicitly sets the requested booking (or clears selection for the general Collect payment button) and resets the payment method. Opening/changing bookings always resets the amount and maximum to that booking's balance. Partial amounts entered afterward remain supported. The admin page script version was bumped from 31 to 32.

## Online checkout handling

The balance endpoint previously reused stored checkout URLs without refreshing provider status and returned an active-payment message for every PDO error. It now verifies an existing linked checkout after checking booking ownership/eligibility, outside database locks, then re-locks/re-reads the booking before preparing/reusing payment.

- Same eligible active session: original source/environment/amount/ownership guards remain, then resume its existing checkout.
- Paid session: use the existing authoritative payment reconciler, then re-read the balance and booking state.
- Provider-confirmed expired session: mark only its pending ledger attempt expired, preserving audit history; prepare a new session from the current balance.
- Processing, verification failure, mismatched identity/reference/mode: no replacement checkout. Local age or QR expiration alone never releases a payment.
- Different active source or amount: still blocked to prevent duplicate collection. Finish/cancel the original payment before starting a different one. A pending operator-origin payment exists in the inspected local ledger, but its provider status was not fetched during this task; it has not been cancelled or rewritten.
- Only a duplicate on `uq_payment_active_booking` produces a concurrent-payment error. Other database errors use a separate preparation-error message.

The exact occasional error from the user's live environment has not been provided, so that live cause is not claimed as confirmed.

PayMongo documentation: https://docs.paymongo.com/docs/payment-channels-key-concepts and https://docs.paymongo.com/reference/checkout-session-resource . Sessions do not expire automatically; retrieve their provider state before replacing them.

## Validation

PHP syntax and JavaScript syntax checks passed. New fixture tests: `scripts/admin_collection_modal_test.cjs` and `scripts/balance_checkout_pending_test.php` (15 checkout checks). Existing walk-in acceptance, walk-in payment flow, operator payment flow and walk-in collection suites passed. These cover row switching, dropdown changes, general modal opening, partial payment preservation, provider failures, expired/processing/paid states, environment/reference mismatches, concurrent state guards and existing cash/QR acceptance behavior.

Backups of existing files: `storage/private/admin-collection-fix/before/`. No authenticated browser payment or actual PayMongo call was used. Staging should verify reopening an unpaid checkout, a confirmed expired checkout, and a source/amount conflict with test-mode payments before live deployment.

## TP26-0003: operator checkout resumed from admin panel

The user's exact error was traced read-only to booking ID 8 / TP26-0003, pending payment transaction 79, PHP 10.00, source `operator_booking_payment`, assigned operator 1. Main-admin collection previously rejected it solely because it expected `admin_booking_payment`.

The main admin can now resume the same operator checkout after provider verification when its current assigned operator, booking ID/domain, amount, pending status and secure PayMongo URL match. It does not create a second checkout, replace the original source/operator attribution or bypass environment checks. A server-written transaction metadata grant permits only the resuming administrator to monitor and safely cancel it using the existing provider-expiry safeguards. Normal operator access is retained. Hotel/tourist sources and mismatched amounts/assignments remain blocked. Completion requests do not use this exception.

Files: `payments/AdminCheckoutResume.php`, `payments/create-balance-checkout.php`, and the payment-status handler in `admin/adbookings.php`. Existing files backed up in `storage/private/admin-collection-fix/before-operator-resume/`.

Validation: 18 focused operator/admin resumption checks plus the 15 pending-checkout checks, modal test, operator flow, walk-in flow and acceptance suites passed. Read-only evaluation confirmed the actual local transaction is eligible for the new guard. Its status, amount and metadata were left unchanged; a resume grant is written only when the authenticated administrator actually retries collection. No real provider request, checkout creation/cancellation, payment, notification or live deployment was performed during this follow-up.
