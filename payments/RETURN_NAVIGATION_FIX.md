# PayMongo return navigation fix

## Root cause and behavior

The cancel endpoint previously selected a destination using transaction metadata
but never checked payment status. Profile and staff JavaScript then displayed
cancellation solely from `payment_return=cancelled`. Admin/operator history
handlers also posted `cancel_pending` automatically and displayed cancellation
without examining whether a payment had already been credited. The phone page
treated `result=cancelled` as cancellation while a transaction was still pending,
which disabled its payment polling.

The cancel endpoint now runs the normal success return handler. An existing paid
ledger entry wins immediately. A pending entry can use the existing authenticated
Checkout Session retrieval and unchanged PaymentReconciler. The ledger is read
again after retrieval, including when retrieval fails, to catch a concurrent
webhook. Return screens show paid, terminal failure, or pending according to
server state. Browser Back no longer requests cancellation automatically.

Initial bookings retain `booking_success.php?token=...` and its existing guarded
success page. Tourist balance payments retain the profile success confirmation.
Staff payments retain their admin/hotel/operator booking confirmations; phone
returns retain their Payment Verified page. No new success page was added.

## Files to deploy together

| File | Change |
| --- | --- |
| `payments/PaymentReturnStatus.php` (new) | Shared ledger lookup and existing API/reconciliation fallback. |
| `payments/payment-success.php` | Uses shared resolution; status-only JSON for history navigation. |
| `payments/payment-cancel.php` | Delegates to the same verified return flow. |
| `payments/payment-phone-return.php` | Database-driven result, bounded polling, refresh on cache restoration. |
| `php/profile.php` | Ownership-checked status lookup for initial and balance payments; verifies cancel returns. |
| `admin/adbookings.php` | Checks status for cancel/history returns; removes automatic history cancellation. |
| `operator/opbookings.php` | Same correction for operator returns. |
| `Hobookings.php` | Checks cancellation returns through existing polling; remembers checkout navigation. |
| `js/paymongo-return-navigation.js` (new) | History restoration checks server state before using the existing success handler. |
| `public/tour_booking.php` | Remembers the transaction token before hosted checkout navigation. |
| `public/hotel_booking.php` | Same navigation tracking for accommodation bookings. |
| `payments/create-booking-checkout.php` | Adds the existing return token to the successful response only. |
| `payments/create-balance-checkout.php` | Returns the existing token for tourist balances as well as staff payments. |

Additional files: `scripts/paymongo_return_fixture_test.php`,
`scripts/paymongo_return_browser_test.js`, and this report.

## Trust, timing and repeated processing

- A URL flag or history entry is a lookup hint, never proof of payment. Return
  tokens must have the existing 64-character format and match a PayMongo ledger
  record. The profile retains its tourist ownership and metadata-source checks.
- Only an already-paid ledger entry produces success. Pending API verification
  passes through the unchanged reconciler's mode, amount, currency, reference,
  identity and idempotency checks. A response saying paid without a paid Payment
  resource is rejected.
- Already-paid returns do not call PayMongo or run fulfillment. Pending returns
  use the same reconciliation mechanism that normal success returns already used.
  Transaction locking and unique constraints remain unchanged.
- Profile, history and phone checks make at most four sequential requests, with
  1.5-second gaps. Hotel return polling uses four attempts with its existing
  2.5-second gaps; active phone-payment monitoring retains its prior limit.
  Admin/operator return polling retains its existing 15-attempt limit. Network
  time is additional and retains the configured API timeout. No endless polling
  was added; the phone's previous endless interval was removed.
- Unresolved pending status stays pending. Unavailable API verification does not
  prove cancellation. Existing terminal failed/expired/cancelled states retain
  failure handling. Explicit staff cancellation controls still exist.
- New failure logs contain only internal transaction ID and exception class;
  lookup failures log exception class only. No credentials, provider payloads,
  payment details or return tokens are added to logs.

## Verification

Commands:

```text
php scripts/paymongo_return_fixture_test.php
node scripts/paymongo_return_browser_test.js
php scripts/security17_https_regression.php
php scripts/security11_fixture_test.php
php scripts/paymongo_refund_critical_fixture_test.php
```

Results: 212 existing mode checks, 79 new return checks, and 80 browser-script
checks passed. HTTPS, security #11 and refund fixtures passed. Modified PHP files
and extracted inline JavaScript passed syntax checks; `git diff --check` passed.

| Required scenario | Offline test / trace |
| --- | --- |
| 1. Unpaid + chevron | Empty provider payment list remains pending; no credit or success redirect. |
| 2. Paid + Return to Merchant | Paid ledger resolves immediately; existing route destinations retained. |
| 3. Paid + chevron | Cancel delegates to success; profile/staff/phone script fixtures suppress false cancellation. |
| 4. Paid + refresh | Repeated resolution skips API/fulfillment; booking count and credited amount remain unchanged. |
| 5. Paid + browser Back/Forward | Actual history script runs against server-response stubs; repeated restoration uses the existing success route. Staff history handlers trace to verification, with no cancellation POST. |
| 6. Delayed webhook | Mock verified paid response reconciles; pending-to-paid polling resolves; webhook-during-API-error is re-read as paid. Persistent pending stops at the retry bound. |
| 7. Failed/expired | Terminal ledger fixtures and return-script fixtures preserve failure handling. |
| 8. Duplicate webhook | Real reconciler called twice after return verification; one ledger transaction, unchanged credit/balance and no repeated fulfillment log. |
| 9. Paid balance + chevron | All four domain balance fixtures reconcile once; profile/staff script fixtures use existing success confirmation. |

Initial downpayment and full-payment fixtures cover hotel, package, boat and
tourguide bookings. Negative fixtures reject wrong amount, currency, mode,
session, invalid/unknown token, non-PayMongo provider, and a paid flag without a
paid resource.

These are isolated SQLite tests with mocked provider responses and Node browser
stubs, plus source tracing. They do not exercise real browser cache behavior,
Hostinger, PayMongo's hosted UI, or concurrent MySQL connections. No production
database, real payment, refund, credentials or Hostinger configuration was changed.
Deploy all runtime files together, including the two new helper files. A hosted
browser check remains necessary to confirm the UI behavior in production.
