# PayMongo test/live audit and rollout

## Scope

Code preparation only. No Hostinger setting, private environment file, API key,
PayMongo registration, real payment, application database, commit, push or
deployment was changed. There is no database migration in this change.

## Audit before editing

| Requested item | Finding |
| --- | --- |
| A. Files | Core: `payments/PayMongoService.php`, `PaymentHelper.php`, `paymongo-config.php`, `paymongo-webhook.php`, `PaymentReconciler.php`, `RefundWebhookReconciler.php`, `BookingCheckoutService.php`, both `create-*-checkout.php` endpoints and all three payment return pages. Related: `admin-payment-handoff.php`, `admin/adpaymenttransactions.php`, `admin/adbookings.php`, `operator/opbookings.php`, `Hobookings.php`, `php/profile.php`, `php/booking_refunds_helper.php`, cancellation helpers, refund emails/institutions, tourist booking forms and staff/payment JavaScript. Whole-project searches also covered payment totals, reports and schema definitions. |
| B. Environment | Existing PayMongo settings: `PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_ID`, `PAYMONGO_WEBHOOK_SECRET`, `PAYMONGO_PUBLIC_BASE_URL`, `PAYMONGO_TIMEOUT_SECONDS`, `PAYMONGO_CA_BUNDLE`, `PAYMONGO_CA_PATH`, `PAYMONGO_DNS_SERVERS`, `PAYMONGO_API_RESOLVE`. Shared: `APP_ENV`, `APP_URL`, `PUBLIC_APP_URL`, `LOCAL_APP_URL`, `ITOUR_PRIVATE_ENV_PATH`, `REFUND_DESTINATION_ENCRYPTION_KEY`. Added: `PAYMONGO_MODE`, optional `REFUND_LEGACY_DESTINATION_ENCRYPTION_KEY`. |
| C. Test restrictions | Service key/configuration checks and response guard; signature slot; webhook mode; checkout/payment reconciliation; admin refund creation, refresh and error handling. |
| D. Route | `payments/paymongo-webhook.php`; `.htaccess` does not relocate it. At the production domain root: `https://itourmercedes.com/payments/paymongo-webhook.php`. Hosting reachability was not tested. |
| E. Signature | Raw-body HMAC-SHA256 over timestamp plus body, `hash_equals`, 300-second window; previously `te` only. Now `te`/`li` selected by mode, with no fallback. |
| F. Reconciliation | Signed webhook or authenticated API retrieval -> local transaction by session/reference -> locks -> amount/currency/ownership checks -> draft or balance update -> ledger marked paid -> commit. Browser redirects alone are not proof. |
| G. Methods | Tourist: `card`, `gcash`, `qrph`. Staff: `qrph`. No method was added; live account availability is a dashboard check. |
| H. Idempotency | Existing migration defines unique merchant reference, checkout session, payment, event, idempotency key, return token and active booking keys. Paid rows return early under a lock. Refund IDs/event IDs are unique, and refund status is monotonic. Actual Hostinger indexes were not inspected. |
| I. Amounts | Provider amount is matched in centavos to the server-created transaction, with PHP currency and reference/session binding. Existing 20% partial-payment and remaining-balance calculations are preserved. Non-integer provider amounts are now rejected. |
| J. Mode | Previously hardcoded test. Now provider flags and local record mode must match configuration; stored metadata also prevents cross-mode link reuse and mixed booking funding. Unmarked historical rows are test records. |
| K. Secrets | No apparent real PayMongo key or webhook secret found in tracked source. Password-pattern matches in `php/profile.php` and `public/signup.php` were UI/validation text. Examples/fixtures contain placeholders. Private environment values were not read or changed for this audit. This was not a historical Git-secret audit. |
| L. Tables | `payment_transactions`, `booking_checkout_drafts`, `bookings`, `hotel_room_bookings`, `booking_reference_sequences`, `booking_refunds`, `booking_cancellation_requests`, `paymongo_webhook_events`, `admin_activity_logs`; existing checkout authorization/pricing also reads tourist, staff, inventory and pricing tables. |
| M. Risks | Old links could survive a mode switch; records lacked mode markers; a late API result could reset paid to pending; old refund ciphertext could depend on the test secret; a refund webhook could arrive before its local provider ID was saved. Guards were added. Synchronous webhook processing still requires hosting latency monitoring. |
| N. Planned changes | Payment mode/signature/provider helpers, checkout metadata/linking, reconciliation, admin refund checks, handoff, fixtures and documentation. No policy or schema redesign. |

## Files modified: before -> after and reason

| File | Change |
| --- | --- |
| `payments/PaymentHelper.php` | No common mode handling -> strict mode/flag/metadata and prior-funding checks; both signature modes with preserved test wrapper; safe diagnostics; shared checkout linking that never changes paid status. |
| `payments/PayMongoService.php` | Test-only credentials/resources -> configured-mode key pairs and nested resource checks; required boolean mode for Payment/Checkout Session; mode-aware verifier; redacted errors. |
| `payments/paymongo-webhook.php` | Test signatures/events only -> configured-mode verification for both existing event envelopes; refund mode comes from signed event; unmatched refund resources retry before the event is marked processed. |
| `payments/PaymentReconciler.php` | Test-only checks -> provider and local modes, integer centavos, local provider check, duplicate Payment-ID binding and safe outcome logs. Existing business updates preserved. |
| `payments/RefundWebhookReconciler.php` | Relied on handler's test restriction -> checks signed mode and original payment metadata; preserves mode in snapshot, refund binding, totals and monotonic status. |
| `payments/create-booking-checkout.php` | No mode marker -> local/provider metadata, early config validation, HTTPS check and safe checkout linking; failures cannot downgrade processed transactions/drafts. |
| `payments/create-balance-checkout.php` | Cross-mode reuse possible and API save reset paid status -> mode guards/metadata, config check and status-preserving link; log reflects actual mode. |
| `admin-payment-handoff.php` | Any pending staff checkout forwarded -> rejects a link from another mode. |
| `admin/adpaymenttransactions.php` | Refund guards assumed test -> current mode tied to original transaction, exact refresh refund ID, shared redaction; refresh cannot overwrite succeeded status. API/manual routing preserved. |
| `php/booking_refunds_helper.php` | Provider mode coerced to false -> preserve supplied mode/absence; optional retained legacy decryption material survives API key rotation. Eligibility/allocation functions unchanged. |
| `payments/.env.example` | No mode and obsolete encryption comment -> explicit test mode, correct dedicated encryption requirement, legacy option and existing networking override. |
| `payments/README.md` | Test-only setup -> both configurations, webhook route/events and rollout pointer. |
| `payments/LIVE_MODE_ROLLOUT.md` | New audit, changes, verification, deployment and rollback guide. |
| `scripts/paymongo_mode_fixture_test.php` | New isolated offline fixtures running real reconciliation SQL; no application DB bootstrap or API requests. |

## Verification

The mode fixtures pass **212 checks**. Existing `security11_fixture_test.php`
and `paymongo_refund_critical_fixture_test.php` pass. Modified PHP files pass
syntax checks; `git diff --check` passes.

Tests cover matching/mismatched/missing keys; missing-mode default; invalid
modes; both signatures; wrong secrets; tampering; stale/future timestamps;
duplicate signature fields; nested modes; legacy/cross-mode metadata; real
draft conversion for hotel, package, boat and guide; 20% downpayment, later
balance, upfront full payment; duplicate delivery; independent payment rows;
wrong amount/currency/reference/session; failed/expired unpaid checkout;
missing Payment mode; staff completion; hotel checkout charges; late checkout
linking; unique Payment-ID rollback; refund mode/binding/duplicates and late
states; preserved claim links; event deduplication; diagnostics; legacy and
dedicated refund encryption.

| Flow | Evidence and limits |
| --- | --- |
| Create booking/checkout | Traced existing authentication, server-calculated totals, drafts, configured service, methods and idempotency key. Authenticated browser/API creation was not executed. |
| Signature/webhook | Both signature modes and bad signatures tested offline. Handler was traced for verification before parsing, both envelopes, mode rejection and duplicate success responses. Actual Hostinger HTTP delivery remains a deployment gate. |
| Initial payment | Real `BookingCheckoutService` and `PaymentReconciler` executed offline for each domain and both modes. Partial/full amounts and booking links verified. |
| Balance/staff | Actual balance/status updates, staff completion and hotel checkout charges executed offline. Endpoint mode/ownership checks and phone dispatch traced. No push was sent. |
| Duplicates | Repeated reconciliation leaves amounts, booking count and activity-log count unchanged; unique Payment-ID failure rolls back credit. No payment email is sent by the reconciler itself. |
| Refunds | Existing critical fixtures and new mode/reconciliation checks pass. Existing API attempts, partial allocation, manual recording and claim link handling preserved. No actual refund submitted. |
| Success/cancel/phone return | Return tokens select server records; success fallback retrieves PayMongo before reconciliation. Query parameters alone never mark paid. |
| Failed/expired | No paid Payment resource means no credit. `payment.failed` remains acknowledged without changing bookings. Existing policy lets a genuinely verified payment win a cancellation race. |

The fixture DB is SQLite with a small MySQL syntax adapter. It does not prove
MySQL concurrency/row locking, deployed indexes, account capabilities, real
checkout completion, HTTP/webhook delivery, email/push or actual refunds.
Production and development databases must remain separate. Existing runtime
schema helpers were not invoked by these tests.

No migration was introduced or run. Back up production before any later schema
or index repair; first verify its actual indexes rather than assuming the
migration file proves they exist.

## Environment configuration

Use Hostinger's existing private environment outside `public_html` (normally
`../private/itour-mercedes.env` relative to the project). The loader searches
parent private directories or uses process `ITOUR_PRIVATE_ENV_PATH`. A web-root
`.env` is only a local XAMPP fallback. Process variables override file values.

After production passes the TEST gate, set these values together:

```dotenv
APP_ENV=production
APP_URL=https://itourmercedes.com
PUBLIC_APP_URL=https://itourmercedes.com
PAYMONGO_PUBLIC_BASE_URL=https://itourmercedes.com
PAYMONGO_MODE=live
PAYMONGO_PUBLIC_KEY=pk_live_YOUR_PUBLIC_KEY
PAYMONGO_SECRET_KEY=sk_live_YOUR_SECRET_KEY
PAYMONGO_WEBHOOK_ID=hook_YOUR_LIVE_WEBHOOK_ID
PAYMONGO_WEBHOOK_SECRET=whsk_YOUR_LIVE_WEBHOOK_SECRET
PAYMONGO_TIMEOUT_SECONDS=30
```

The webhook ID records registration; verification uses the signing secret.
Keep existing valid CA/DNS settings. Do not copy Windows certificate paths into
Hostinger. `APP_ENV=production` alone does not select live payments.

Localhost keeps its current keys, tunnel/return URLs and:

```dotenv
PAYMONGO_MODE=test
PAYMONGO_PUBLIC_KEY=pk_test_YOUR_PUBLIC_KEY
PAYMONGO_SECRET_KEY=sk_test_YOUR_SECRET_KEY
PAYMONGO_WEBHOOK_ID=hook_YOUR_TEST_WEBHOOK_ID
PAYMONGO_WEBHOOK_SECRET=whsk_YOUR_TEST_WEBHOOK_SECRET
```

Keep `REFUND_DESTINATION_ENCRYPTION_KEY` unchanged. It is required for new
encrypted refund/payout destinations. If old refund destinations still use
the previous PayMongo secret, retain that original material in the optional
`REFUND_LEGACY_DESTINATION_ENCRYPTION_KEY` before replacing the API secret.
That setting is decrypt-only. If no legacy destinations exist, it is unused.
Do not generate a replacement dedicated key over existing encrypted records.

## Production checklist and deployment order

1. Review the diff and run local offline tests with test configuration.
2. Commit/push only the files listed below; deploy that commit to Hostinger.
3. Keep Hostinger on `PAYMONGO_MODE=test` with matching test keys and webhook.
4. Verify InnoDB tables and existing unique indexes. Read-only checks include
   `SHOW INDEX FROM payment_transactions;` and `SHOW INDEX FROM booking_refunds;`.
   Back up before any required schema repair; this task adds no migration.
5. Complete a PayMongo test checkout through the deployed site. Confirm signed
   webhook HTTP 200, correct transaction/booking, amount, balance and statuses.
6. Verify partial then balance payment, full payment, staff QR, cancel, failure
   and browser return. Exercise the requested methods enabled on the account.
   Check both admin and tourist displays.
7. Resend the paid webhook: amount, booking count, transaction count and side
   effects must not increase. Confirm bad signatures/wrong modes are rejected.
   Review delivery and server logs for errors/timeouts.
8. Resolve/expire pending test checkouts while still in test mode. Historical
   test-funded bookings cannot accept live balances. Use a fresh booking for
   the live smoke test. Review historic test data in production reports
   separately; this code does not delete or reclassify those records.
9. Confirm activation and live `card`, `gcash`, `qrph` availability in PayMongo.
   Confirm refund capabilities separately; live mode does not guarantee QR Ph
   Refund API support. The existing code already attempts eligible QR Ph API
   refunds and retains manual handling. No eligibility was added by this task.
10. Register a LIVE webhook at
    `https://itourmercedes.com/payments/paymongo-webhook.php`. Subscribe to
    `checkout_session.payment.paid`, `payment.failed`, `refund.succeeded`,
    `payment.refund.updated`, `payment.refunded` where available for the account.
    The route must accept public HTTPS POST without login/browser challenge.
    GET returning 405 is expected. Register once per environment.
11. Preserve encryption settings; update the live key pair, live webhook
    secret/ID and `PAYMONGO_MODE=live` together during a controlled payment pause.
    A partly switched configuration deliberately fails closed.
12. Make ONE small real payment yourself using a fresh valid booking. Check the
    live PayMongo Dashboard, local transaction/provider IDs, exact amount,
    remaining balance, payment/booking status, webhook response and admin/tourist
    displays. Resend the webhook and verify it does not credit again.

## Rollback

Keep the dual-mode code. Restore `PAYMONGO_MODE=test`, both test API keys and
the test webhook secret/ID together. Keep encryption material unchanged.
Do not delete live ledger entries, relabel them as test or reset balances.

Test configuration intentionally rejects live webhook deliveries and live
operations. An accepted real charge remains real. Review live payments/refunds
in flight, resolve their provider state and replay legitimate events under
live configuration after fixing the issue. Restoring test settings does not
refund or reverse a live transaction.

## Git commands

The initial worktree was clean on branch `main`. Review for unrelated edits
before staging. No commit, push or deployment was performed.

```powershell
git status --short
git diff --check
git diff
git add -- payments/PaymentHelper.php payments/PayMongoService.php payments/paymongo-webhook.php payments/PaymentReconciler.php payments/RefundWebhookReconciler.php payments/create-booking-checkout.php payments/create-balance-checkout.php admin-payment-handoff.php admin/adpaymenttransactions.php php/booking_refunds_helper.php payments/.env.example payments/README.md payments/LIVE_MODE_ROLLOUT.md scripts/paymongo_mode_fixture_test.php
git diff --cached --stat
git commit -m "Prepare PayMongo integration for secure live mode"
git push origin main
```

Do not stage private environment files or actual credentials.

## Official documentation checked

- [Hosted Checkout quick start](https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start): V2 creation and paid checkout webhook.
- [Webhook key concepts](https://docs.paymongo.com/docs/developer-tools-webhooks-key-concepts): mode-scoped registrations and delivery.
- [Developer security practices](https://docs.paymongo.com/docs/developer-tools-best-practices-1): private keys, raw-body HMAC and idempotency.
- [Developer go-live checklist](https://docs.paymongo.com/docs/developer-tools-go-live-checklist): test verification and webhook monitoring.

The public overview has an abbreviated signature example. This refactor keeps
the project's working timestamped signature protocol and selects `li` in live
mode. Confirm an actual signed delivery during the Hostinger test/live gates.
