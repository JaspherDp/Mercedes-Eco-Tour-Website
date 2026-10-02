# PayMongo payments

The same PHP code supports test and live PayMongo accounts. `PAYMONGO_MODE`
defaults to `test` for compatibility. Only `test` and `live` are valid; the
secret and public keys must both match that mode. `APP_ENV=production` does
not select live payments.

The full audit, file changes, verification results, deployment sequence,
rollback procedure, and Git commands are in [LIVE_MODE_ROLLOUT.md](LIVE_MODE_ROLLOUT.md).

## Private environment

On Hostinger, use the existing private environment file **outside public_html**,
normally `../private/itour-mercedes.env` relative to the project root. The loader
also searches the next two parent directories for `private/itour-mercedes.env`.
Alternatively, set the process variable `ITOUR_PRIVATE_ENV_PATH` to an absolute
private path. Process environment variables take precedence over file values.
A `.env` inside `public_html` is not the production configuration source.
Local XAMPP retains its protected project-root `.env` fallback.

Deploy and verify production with test credentials first. After that gate:

```dotenv
APP_ENV=production
APP_URL=https://itourmercedes.com
PUBLIC_APP_URL=https://itourmercedes.com
PAYMONGO_PUBLIC_BASE_URL=https://itourmercedes.com
PAYMONGO_MODE=live
PAYMONGO_SECRET_KEY=sk_live_YOUR_SECRET_KEY
PAYMONGO_PUBLIC_KEY=pk_live_YOUR_PUBLIC_KEY
PAYMONGO_WEBHOOK_ID=hook_YOUR_LIVE_WEBHOOK_ID
PAYMONGO_WEBHOOK_SECRET=whsk_YOUR_LIVE_WEBHOOK_SECRET
PAYMONGO_TIMEOUT_SECONDS=30
```

For localhost, retain existing URL/tunnel settings and credentials:

```dotenv
PAYMONGO_MODE=test
PAYMONGO_SECRET_KEY=sk_test_YOUR_SECRET_KEY
PAYMONGO_PUBLIC_KEY=pk_test_YOUR_PUBLIC_KEY
PAYMONGO_WEBHOOK_ID=hook_YOUR_TEST_WEBHOOK_ID
PAYMONGO_WEBHOOK_SECRET=whsk_YOUR_TEST_WEBHOOK_SECRET
```

Keep `REFUND_DESTINATION_ENCRYPTION_KEY` unchanged. It is required for new
encrypted refund/payout destinations. If old refund destinations still depend
on the old PayMongo secret, retain that original material in the optional
`REFUND_LEGACY_DESTINATION_ENCRYPTION_KEY` before switching API keys. This is a
decrypt-only fallback; new ciphertext always uses the dedicated key. Never
commit either encryption value.

## Webhook

With the project deployed at the domain root, register this production URL:

`https://itourmercedes.com/payments/paymongo-webhook.php`

Subscribe to the events the existing handler supports:

- `checkout_session.payment.paid`
- `payment.failed`
- `refund.succeeded`
- `payment.refund.updated`
- `payment.refunded`

If a legacy event is unavailable in the dashboard, use the supported refund
event offered for the account; the handler retains compatibility with both
families. Register once per environment, not once per checkout. Use the signing
secret issued for that registration. The production endpoint must receive
public HTTPS POSTs without authentication or a browser challenge. A browser
GET returning 405 is expected.

Signatures use the existing structured `Paymongo-Signature` protocol:
HMAC-SHA256 of `timestamp + "." + raw_body`. Test mode checks `te`; live mode
checks `li`. The other signature slot is never a fallback. Invalid signatures
return 401; timestamps beyond five minutes are rejected. Mode mismatches return
400. Unknown authenticated event types return 200 without changing records.
Payment and Checkout Session resources require an explicit boolean mode.
Refund resources that omit it inherit the verified event mode or are obtained
using the configured authenticated API, and remain bound to the original local
transaction's mode.

## Payment behavior

- Tourist booking and balance checkout request `card`, `gcash`, and `qrph`.
- Staff checkout requests only `qrph`. Confirm account enablement separately.
- Booking-time checkout retains the current 20% partial or full payment policy.
- Browser returns retrieve a session from PayMongo before reconciliation.
- Reconciliation checks mode, PHP currency, integer centavos, merchant reference,
  session ID, payment ID, local ownership, and the expected amount.
- Transactions, row locks, and existing unique indexes protect repeated credit.
  Duplicate paid sessions return success without repeating updates. A late
  checkout creation response cannot reset paid status.
- New transactions store `paymongo_mode` in existing JSON metadata. Unmarked
  historical transactions are test records. A booking funded in one mode cannot
  receive PayMongo payments in the other mode.
- Refund methods, eligibility, allocation, cancellation policy, claim links and
  manual handling retain their behavior. The existing code already attempts QR
  Ph API refunds in eligible cases; live support does not add that behavior or
  guarantee that PayMongo will permit it for this account.

No database migration is introduced. The deployed ledger must already have the
unique indexes defined in `payment_transactions_migration.sql` and use InnoDB.
Verify this before launch; back up production before any later schema repair.

## Offline verification

```powershell
php scripts/paymongo_mode_fixture_test.php
php scripts/security11_fixture_test.php
php scripts/paymongo_refund_critical_fixture_test.php
```

The mode fixtures use isolated SQLite and real reconciliation code. They do
not call PayMongo, load the application database, or test MySQL concurrency.
Complete the Hostinger test-mode checkout/webhook check before adding live keys.
