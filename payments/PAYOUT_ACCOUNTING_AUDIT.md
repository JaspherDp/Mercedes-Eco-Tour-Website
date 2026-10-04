# Payout accounting audit and rollout

## Scope and findings (2026-10-03)

Read-only inspection covered the configured database, existing migrations, the payment/refund implementation, and all three administrative areas. No payment, refund, payout, persistent schema migration, key change, hosting change, commit or deployment was executed. The authorized follow-up saved verified fee metadata for historical payments in the local database only. Gross payments, booking balances and settled history were not rewritten.

The database has `payment_transactions.amount_minor` (integer centavos), `metadata` (JSON), and `provider_payouts.gross_amount` (DECIMAL). It has no existing fee or net columns, and transaction metadata contains no fee information. The inspected data contains 62 paid PayMongo transactions, two paid offline/cash records, 33 bookings and 33 pending payout records. These counts include the configured database's historical environments; they are not a claim that all records are live payments.

The initial audit found 33 bookings without verified stored fees. The local backfill now covers all 63 successful PayMongo payments (62 newly enriched and one already known). Four booking receipt totals still differ from their successful transaction sums. The read-only calculation flags those four for reconciliation without changing either ledger. No successful or active refund records were found for these bookings, so refund behavior was verified with isolated fixtures.

### Files and tables traced

| Area | Files inspected and accounting responsibility |
| --- | --- |
| Main Admin | `admin/adearningsdisbursements.php` (ledger sync, eligibility, settlement, summaries, CSV, receipt); `js/adearningsdisbursements.js` (filters, summaries, settlement modal); `admin/adpaymenttransactions.php`, `js/adpaymenttransactions.js` (gross transactions, refunds); `admin/adreportsanalytics.php`, `js/adreportsanalytics.js` (successful gross collections); `admin/adbookings.php` (gross booking receipts); `admin/adhomepage.php` (operational dashboard, no payout calculation). |
| Operator | `operator/opearnings.php`, `js/Ho_earnings.js` (earnings and payout history); `operator/oppayments.php`, `js/op_payments.js` (transaction display); `operator/opbookings.php` (gross receipts); `operator/ophomepage.php` (booked values and gross collections). |
| Hotel | `Hoearnings.php`, `js/Ho_earnings.js`; `Hopayments.php`, `js/Ho_payments.js`; `Hobookings.php`; `Hohome.php`; `Ho_common.php`. These respectively supply earnings, transactions, booking receipts, dashboard collections and shared property access. |
| Payment infrastructure | `payments/PaymentReconciler.php`, `payments/PayMongoService.php`, `payments/PaymentHelper.php`, `payments/BookingCheckoutService.php`, checkout/return/webhook callers. |
| Refund infrastructure | `php/booking_refunds_helper.php`, `php/booking_cancellations_helper.php`, `payments/RefundWebhookReconciler.php`, refund actions in `admin/adpaymenttransactions.php`. |
| Route aliases | Root `adearningsdisbursements.php`, `adpaymenttransactions.php`, `adreportsanalytics.php`, `adhomepage.php`, `adbookings.php`, `opearnings.php`, `oppayments.php`, `ophomepage.php`, `opbookings.php` delegate to their administrative implementations. |

Financial tables: `payment_transactions`, `provider_payouts`, `bookings`, `hotel_room_bookings`, `booking_refunds`, `booking_cancellation_requests`. Related identity/destination tables: `provider_payout_destinations`, `operators`, `tour_guides`, `boats`, `hotel_resorts`, `tourist`, `admin_users`. Draft checkout and webhook-event tables remain unchanged.

### Root cause and old calculation

Payment reconciliation correctly records each original gross transaction and accumulates it in `bookings.payment_amount` or `hotel_room_bookings.amount_paid`. The earnings pages use these booking totals directly as payable amounts, without aggregating fees from individual payments. Main Admin settlement uses the same gross value. Cancellation logic substitutes the non-refundable retained portion.

`provider_payouts.gross_amount` is also overwritten with that settlement/retention amount, mixing gross collections and disbursements. Settled-history totals sum that column. No transaction-fee deduction exists.

Refund earnings logic mainly checks the latest cancellation workflow and retained amount, rather than consistently summing all successful `booking_refunds` records. A successful refund outside the expected latest cancellation path can consequently be missed.

## New calculation and authoritative fee source

PayMongo documents integer-centavo `amount`, `fee`, and `net_amount` on the [Payment resource](https://docs.paymongo.com/reference/payment-source). The existing `PayMongoService::retrievePayment()` performs the needed GET; no new endpoint, fee schedule or estimated percentage is introduced. Balance/settlement endpoint integration is unnecessary when this transaction resource supplies verified values.

A read-only request for one explicitly mode-tagged paid transaction failed without an HTTP response, including after retrying outside the sandbox. The follow-up identified a local TLS trust-chain failure. A temporary CA bundle assembled from the machine's already trusted Windows root certificates allowed verified HTTPS lookups, without disabling TLS validation or changing application/hosting settings. Actual local historical fees have now been retrieved. Live production data was not accessed or changed.

- **Gross:** successful transaction `amount_minor` sums; original transactions and booking receipt columns are never rewritten by accounting code.
- **PayMongo fee:** the verified fee for each successful PayMongo transaction. Stored separately under `metadata.paymongo_accounting`, with payment ID, gross, fee, net, mode, source and verification time. Offline/cash receipts have zero PayMongo fees.
- **Net before refunds:** gross minus the sum of individual fees.
- **Net provider entitlement:** `max(0, gross - successful refunds - fees)`. For cancellation retention, first cap the post-refund retained amount at the existing policy's non-refundable amount, then deduct fees once. A negative cash position results in no provider payout; it is not paid as a negative transfer.
- **Amount settled:** immutable recorded transfer amount. New settlements use `net_amount`; historical rows with NULL `net_amount` retain the old recorded `gross_amount`. No retroactive reduction or automatic second transfer occurs.
- **Remaining to settle:** known, unsettled net entitlements, subject to existing completion/retention eligibility. Incomplete totals display Pending review instead of an amount with a pending suffix. The explanatory block above the stat cards has been removed.

New payment confirmation has only a best-effort **post-commit** accounting hook. It validates fees already present in the authenticated Payment resource, makes no API call, catches enrichment failures, and cannot roll back or fail the confirmed payment. Existing return, webhook validation, idempotency, checkout, balances and booking-confirmation logic are unchanged.

Historical/missing fees can be retrieved from a CSRF-protected Main Admin “Retrieve PayMongo fees” action for the booking, or the CLI utility. Those paths make only GET requests to PayMongo and write verified accounting metadata locally. Payment confirmation does not depend on them. All six authorized earnings/transaction pages also run small asynchronous fee-sync batches automatically. These use the existing page authorization, CSRF checks and server-side provider ownership scope, cache successful fees, atomically claim missing fees and throttle failed attempts for five minutes. The page reloads calculated totals after successful retrieval when no dialog or form input is active. Mode, payment ID, paid status, currency, amount and `gross - processing fee - foreign card fee = net` must match. The exact returned `foreign_fee` is included when present, with its components preserved in metadata. No fee percentage is configured. Explicitly tagged other-mode records are skipped; untagged historical records may be fetched under the current configured mode, but the returned resource must match that mode before saving. Checkout and confirmation mode rules remain unchanged.

Failed, cancelled, expired, pending and abandoned transactions do not contribute. Each successful downpayment, balance payment or full payment contributes its own gross and fee. Unknown, inconsistent or missing fees stay NULL/unavailable, never an estimate or implicit zero. Receipt/ledger mismatches also block settlement.

Successful refunds are summed by booking using the existing `hotel`/`tour` refund domains. This includes manual refunds without a payment-transaction link and QR Ph manual fallback. Failed refunds are not deducted; active refunds hold settlement. Refund routing, refund creation and cancellation policy are unchanged. Fees are not assumed to be refunded. Changes after a settlement are shown as a manual reconciliation difference when the current net is known; the original transfer remains intact.

Settlement retains existing authorization, CSRF, provider/destination validation, row locking, manual transfer reference and repeated-settlement protection. It rereads transaction/refund records under the settlement transaction and checks a signed context containing the reviewed accounting amounts. It refuses unavailable fees, active refunds, nonpositive net, missing migration, or changed amounts. No bank disbursement is implemented.

## Display changes

Main Admin, Operator and Hotel earnings retain gross customer payment, show fee/net/refund breakdowns and clear pending amounts, and distinguish historical recorded settlements. CSV exports and detail drawers carry the same distinctions. Main Admin's settlement modal displays gross, fees, refunds and the **net amount to settle**; new receipts preserve settlement-time snapshots. Unknown fees and receipt discrepancies show “Accounting review”.

All three transaction pages retain the original gross payment and add fee/net-before-refunds information to the table, details and CSV. Customer payment receipts still show the original amount. Dashboard/report collection labels explicitly identify gross payments. Booking receipt and balance calculations are unchanged. Earnings charts remain booking-month cohorts, as before; they are not bank settlement-date revenue reports.

Arithmetic for fee validation, net entitlement, settlement snapshots and accounting totals uses integer centavos; persistent payout values are DECIMAL strings. Existing chart/formatting code still accepts peso numbers for presentation.

## Exact changed files

Modified:

- `payments/PaymentReconciler.php`
- `admin/adearningsdisbursements.php`
- `admin/adpaymenttransactions.php`
- `admin/adreportsanalytics.php`
- `operator/opearnings.php`
- `operator/oppayments.php`
- `operator/ophomepage.php`
- `Hoearnings.php`
- `Hopayments.php`
- `Hohome.php`
- `js/adearningsdisbursements.js`
- `js/Ho_earnings.js`
- `js/adpaymenttransactions.js`
- `js/Ho_payments.js`
- `js/op_payments.js`
- `js/adreportsanalytics.js`

Added:

- `php/payout_accounting.php`
- `php/payout_fee_sync.php`
- `js/payout_fee_sync.js`
- `scripts/payout_accounting_browser_fixture_test.js`
- `styles/payout_accounting.css`
- `provider_payout_accounting_migration.sql`
- `scripts/sync_paymongo_fees.php`
- `scripts/payout_accounting_fixture_test.php`
- `payments/PAYOUT_ACCOUNTING_AUDIT.md`

Pre-existing workspace modifications were preserved. No other task changes were made to booking/payment/refund flows or configuration.

## Database migration and production steps

1. Review `provider_payout_accounting_migration.sql` against the deployment schema. It adds only nullable DECIMAL `paymongo_fee_amount`, `refunded_amount`, and `net_amount` to `provider_payouts`. It does not backfill or reinterpret historical payouts. Run it once under the normal deployment procedure. **It has not been executed.** The new pages tolerate an unmigrated schema but settlement is deliberately disabled.
2. Deploy the listed PHP, JS and CSS files together. JS version parameters were bumped. No new dependencies or configuration keys are required.
3. In the deployment environment, run `php scripts/sync_paymongo_fees.php` for a read-only provider audit. Review unavailable and other-mode counts. This audit option does not change local records.
4. Use the Main Admin per-booking fee retrieval action, or deliberately run `php scripts/sync_paymongo_fees.php --apply` to save verified fee metadata. The apply operation was run against the local database only, using actual provider responses. Production remains untouched. It changes neither gross transactions nor payout history. The authorized pages now fetch missing fees automatically; the CLI remains available for bulk audit/backfill. Do not switch live/test keys just to resolve historical other-mode records.
5. Review the four existing receipt/transaction mismatches through the normal accounting process. This change intentionally does not repair financial history automatically.
6. Verify a known full payment, downpayment plus balance, and refunded booking in each relevant admin view before recording further manual settlements. The displayed transfer amount must be net. Historical settled discrepancies require manual review; no catch-up payout or recovery is automatic.

## Validation

- 52 isolated accounting checks, including automatic scoped retrieval, caching, throttling, untagged historical modes, foreign card fees and unknown-total labels: single/full/deposit/multiple payments; failed/cancelled/pending/expired/abandoned exclusions; full/partial/manual/QR fallback refunds; active/failed refunds; retention; unavailable fees; odd centavos; authoritative zero fees; cash and mixed receipts; mismatched provider resources; JSON metadata preservation; duplicate capture; hotel/tour ID isolation; aggregate totals; net settlement snapshots; legacy settled values; duplicate-settlement rejection; unknown-fee blocking; optional-enrichment failure isolation.
- Existing payment-mode suite: 212 checks passed.
- Existing return suite: 79 checks passed (mock API, isolated SQLite).
- Existing critical refund suite passed.
- Provider-summary JavaScript rendering fixtures passed for unknown, mixed and fully verified totals.
- Automatic fee-sync and caching passed against connection-local MySQL temporary tables with a mock provider; persistent records were untouched by this fixture.
- PHP lint passed for all changed/new PHP files. Node syntax checks passed for changed JavaScript.
- Changed Operator/Hotel SELECTs and Main Admin settlement SELECT were executed against the configured MySQL database with empty scopes, read-only. Batch accounting also ran read-only against current records.

No live payment, payout or refund was performed. No persistent migration was run. Verified historical fee backfill was completed only on the local database. Full authenticated browser rendering, MySQL concurrent-session settlement behavior, and successful live provider fee retrieval were not exercised here; SQLite fixtures do not establish those properties.

## Authorized local test-data cleanup

The user designated TP26-0001, TG26-0003, TB26-0002 and HR26-0001 as test bookings and requested their removal. These exact four local bookings and their linked test records were backed up outside the web root, then deleted in one transaction. Guards verified the local database, exact references, pending-only payout records and test-mode paid PayMongo records. The deletion included four pending payout rows and 38 local payment-history rows (16 paid PayMongo test payments, two offline/cash receipts and 20 unsuccessful attempts). No external PayMongo payment was changed. Live production data remains untouched. The four reconciliation discrepancies described in the initial audit are now removed. No commit or deployment was performed.
