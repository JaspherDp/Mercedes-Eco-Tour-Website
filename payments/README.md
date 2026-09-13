# PayMongo test-mode foundation

This directory contains the shared PayMongo test-mode layer used by tourist
balance payments, admin QR/counter-assisted payments, and booking-time checkout.

Prefer process environment variables or a private environment file outside the
web document root. Set `ITOUR_PRIVATE_ENV_PATH` to an absolute private path, or
use the default `C:\xampp\private\itour-mercedes.env` during local XAMPP
development. A protected project-root `.env` remains a local-XAMPP fallback.

```dotenv
APP_ENV=production
APP_URL=https://your-production-domain.example
PUBLIC_APP_URL=https://your-production-domain.example
PAYMONGO_PUBLIC_BASE_URL=https://your-production-domain.example
PAYMONGO_SECRET_KEY=sk_test_...
PAYMONGO_PUBLIC_KEY=pk_test_...
PAYMONGO_WEBHOOK_ID=
PAYMONGO_WEBHOOK_SECRET=
PAYMONGO_TIMEOUT_SECONDS=30
```

- `PAYMONGO_PUBLIC_BASE_URL` constructs the public webhook URL.
- In production, `APP_URL` and `PUBLIC_APP_URL` are the canonical HTTPS base
  used by PayMongo returns, OAuth, notifications, and application navigation.
- In development, `LOCAL_APP_URL` may point browser returns back to HTTP
  localhost while the public PayMongo endpoints use an HTTPS tunnel.
- `PayMongoService.php` creates Hosted Checkout sessions using PayMongo's
  recommended `/v2/checkout_sessions` endpoint and rejects non-test API keys.
- `PaymentHelper.php` loads `.env`, converts pesos to centavos, validates public
  HTTPS URLs, and verifies test webhook signatures.
- `payment_transactions_migration.sql` must be run manually after review. No PHP
  file automatically creates or alters this table.
- `booking_checkout_migration.sql` adds short-lived booking drafts. Drafts are
  not visible as bookings and are converted to hotel, package, boat, or guide
  bookings only after a paid Checkout Session is verified. A remaining balance
  continues through the existing Tourist Profile or admin counter flows.

## Creating the webhook later

After the production site is online, register this exact endpoint in the
PayMongo dashboard:

`PAYMONGO_PUBLIC_BASE_URL/payments/paymongo-webhook.php`

Subscribe it only to these events:

- `checkout_session.payment.paid`
- `payment.failed`
- `payment.refund.updated`
- `payment.refunded`

Store the webhook ID and PayMongo-issued signing secret in the private server
environment as `PAYMONGO_WEBHOOK_ID` and `PAYMONGO_WEBHOOK_SECRET`. Do not put
either value into source control.

Webhook requests are verified from the raw request body using:

```text
HMAC-SHA256(PAYMONGO_WEBHOOK_SECRET, timestamp + "." + raw_body)
```

The result must match `te` in the `Paymongo-Signature` header. Requests older
than five minutes, invalid signatures, and live-mode events are rejected. Paid
events reconcile the shared ledger and corresponding booking atomically.
Refund events reconcile the refund ledger and unlock cancellation completion
only after the full eligible amount is provider-confirmed as successful.
