# Provider-based bulk settlement review

Implemented locally on October 18, 2026. No deployment, external transfers, production settlement changes, or permanent database migration was performed.

## Files in this change

| File | Purpose |
| --- | --- |
| `admin/adearningsdisbursements.php` | Available-tab checkboxes and action bar; shared individual/bulk POST handling; existing destination validation and encryption; atomic audit recording. |
| `php/provider_bulk_settlement.php` | Selection validation, canonical provider checks, locked eligibility/financial reads, existing payout calculations, exact totals, per-booking settlement snapshots and batch audit data. |
| `js/provider-bulk-settlement.js` | Provider-restricted row selection, integer-centavo totals and action-bar updates. |
| `js/adearningsdisbursements.js` | Clear selections during filtering/pagination, populate existing manual settlement modal, list every selected booking and confirm the batch. |
| `styles/adearningsdisbursements.css` | Compact action bar and review list; accommodate checkbox column while preserving existing ledger column proportions. |
| `scripts/provider_bulk_settlement_test.php` | Synthetic settlement, accounting, rollback, replay and concurrency fixtures; optional MySQL modes. |
| `scripts/provider_bulk_settlement_ui_test.cjs` | Exercise actual selection, filter and modal handlers with an offline DOM fixture. |
| `docs/provider-bulk-settlement-review.md` | Implementation, validation and release notes. |

The workspace already contained other uncommitted changes, including accounting and third-party-fee work. Those changes were preserved. This feature uses the accounting and fee implementations currently present in the workspace; do not deploy an arbitrary subset against older accounting code.

## Audit findings and reused behavior

- Existing settlements are manual records of transfers already made outside iTour. This remains unchanged; the feature makes no payment-provider requests or automatic transfers.
- `provider_payouts` already stores one record per booking/provider, with immutable settled snapshots, settlement method/reference, destination, administrator and timestamp.
- Canonical providers follow the current ledger model: `operator:<operator_id>`, `hotel:<hotel_resort_id>`, `boat:<boat_id>` and `tourguide:<guide_id>`. Display names never establish provider identity.
- Existing `PayoutAccounting::load`, `forBooking` and `settlementAmounts` remain authoritative. Existing fee, refund and cancellation-retention calculations are reused. Bulk sums use integer centavos and database amounts use decimal strings.
- Existing authorization, CSRF token, saved provider destinations, recipient validation, encryption and audit logger are reused. No new permissions model or transfer integration was added.
- No dedicated financial-dispute table/integration was found. Settlement enforces locally recorded booking, payment, refund and cancellation eligibility; it cannot discover disputes not recorded in the application.

## Selection and confirmation

Checkboxes appear in the Available tab. Selecting the first booking restricts subsequent selection to its canonical provider. Other provider checkboxes become disabled and display a reason. Clear Selection re-enables eligible rows.

The header has no Select All checkbox. Bookings are selected individually for one provider. Clear Selection uses a dedicated outlined button with a clear icon, hover feedback and keyboard focus styling; clearing re-enables other providers.

The action bar shows the provider, selected count and exact net total. Filters, search submissions, provider scope changes, pagination, browser history navigation and tab changes clear selection. Selections do not persist across reloads or pages.

Settle Selected Bookings opens the existing manual settlement modal. It lists every selected booking reference and individual payout, count, provider, total and aggregate financial breakdown. Existing bank, wallet, cash, other-channel and reference inputs remain in use. A final confirmation repeats all selected bookings and the recipient/reference. Cancel makes no settlement request.

## Server validation and transaction behavior

Both individual and bulk settlement use the same validation and recording service. Each request:

1. Passes the existing administrator session validation and CSRF check.
2. Validates 1–100 unique positive payout IDs; malformed, duplicate and missing IDs are rejected.
3. Locks selected payout records in numeric ID order and their associated booking records using `FOR UPDATE`.
4. Requires unsettled `pending` or `approved` records, verifies the canonical provider against each current booking and confirms that the provider exists.
5. Requires booking completion or the existing eligible completed cancellation-retention workflow. Locks cancellation requests and rejects unresolved requests/refunds. Completed rescheduling remains supported.
6. Reloads and locks existing payment, refund and fee records through the accounting helpers. Unknown fees, active refunds, inconsistent receipts, unavailable amounts and nonpositive payouts are rejected.
7. Verifies each signed review context against the current server-derived accounting and provider. Submitted frontend totals are not accepted as accounting inputs.
8. Validates the existing manual destination details once for the provider, then writes all per-booking snapshots and the audit entry inside one transaction.
9. Commits only if every write and audit succeeds. Errors roll back the entire batch, including saved destination updates made by that request.

Conditional updates require an unsettled status. Replay or concurrent attempts cannot settle an already-settled row again: they fail safely rather than return a second successful record. A stale or failed request must be reviewed again; the feature does not silently retry a financial operation.

## Records and database changes

No new application tables or columns are required. Existing settled records are not modified.

Each selected booking retains its independent `provider_payouts` row, accounting snapshot, destination, administrator, timestamp, manual method and shared transfer reference. For bulk requests, every settlement note includes the same random `BULK-...` ID, count and exact total, followed by that booking's existing fee breakdown. Existing settlement-record views display this note.

The existing admin activity log stores one JSON audit entry containing the batch ID, canonical provider, payout IDs, booking domains/IDs/references, individual net centavos, total centavos, method, transfer reference and administrator. The log entry and payout writes commit together. Existing booking-level reporting, histories and ledger exports continue to use the same records and fields.

Existing schema setup/sync routines on the admin page were retained; this feature introduces no DDL into the settlement transaction. Release prerequisites include the existing accounting/destination migrations and transactional InnoDB tables, including `admin_activity_logs`.

## Validation results

Passed locally:

- `php scripts/provider_bulk_settlement_test.php`: **71 checks**, synthetic SQLite file; two independent PHP processes demonstrate lock contention and safe rejection; fixture file removed afterward.
- `php scripts/provider_bulk_settlement_test.php --mysql-temporary`: **70 checks**, connection-scoped InnoDB temporary tables. All test data is synthetic. Temporary tables shadow application names only in the test connection and disappear on disconnect; application records are not written.
- `node scripts/provider_bulk_settlement_ui_test.cjs`: actual selection/filter/modal handlers pass provider restrictions, exact totals, individual row selection, deselection, clearing, provider switching, searches, pagination, tabs, HTML escaping, cancelled/confirmed review and individual review reset.
- Existing accounting fixtures: **52 checks**.
- Existing booking-fee fixtures: **24 checks**.
- Existing third-party payout fixtures: **24 checks**.
- Existing provider-summary and third-party modal UI fixtures pass.
- Existing PayMongo critical-refund, return and mode fixtures pass; return fixtures report **79 checks**, mode fixtures **222 checks**. No real API calls or transfers.
- PHP/JavaScript syntax and scoped diff whitespace checks pass.

The financial fixtures verify the requested example exactly: PHP 2,107.90 + PHP 1,994.62 = **PHP 4,102.52**. They also verify same-name/different-ID rejection, different provider types sharing a numeric ID, incomplete/missing bookings/providers, active refunds, forged contexts, changed accounting, failed audit rollback, duplicate settlement rejection, individual hotel/tour settlements and cancellation retention. Every payment and refund field remains unchanged during settlement simulations.

### Remaining release validation

- Cross-connection **MySQL/InnoDB row-lock concurrency** remains pending. The application DB account cannot create an isolated test database, and no test-account credentials were available. SQLite contention and MySQL temporary-table transactional behavior have been tested, but these do not replace the InnoDB concurrency scenario.
- Authenticated end-to-end browser/layout checks and staging smoke checks of exports, dashboards, saved bank/wallet destinations and real administrative role/session flows remain to be performed against staging. Automated UI tests use DOM fixtures, not a rendered browser.
- External disputes not represented in local application records cannot be evaluated by this feature. There is no new dispute ingestion or webhook behavior.
- Selection is deliberately current-page only (15 rows with the current paginator); the backend caps a request at 100 IDs. Long notes are rejected when combined with required audit details would exceed the existing 500-character field.

Automatic approval review rejected an earlier persistent fixture-table fallback because its proposed cleanup could target application tables. That fallback was not applied or run. Connection-scoped temporary-table tests were used successfully instead; no application-table cleanup was performed.

## Deployment instructions — not executed

1. Review the full intended diff against the live version, including any existing local accounting/third-party-fee dependencies. Back up the deployed application files and database using the existing release process.
2. Configure a **localhost test-only** MySQL account with permission to create/drop disposable schemas. Set `ITOUR_BULK_TEST_DB_USER` and `ITOUR_BULK_TEST_DB_PASSWORD` privately, then run `php scripts/provider_bulk_settlement_test.php --mysql`. It refuses non-localhost hosts, creates a random `itour_bulk_test_<16 hex>` schema, tests a second connection against a locked row, and removes only that generated schema in `finally`. Do not grant schema-creation permission to the application's production account.
3. Run the offline and temporary-table suites above, then perform the remaining staging browser/role/export/dashboard and saved-destination checks. Verify all participating real tables use InnoDB. Resolve failures before approving a release.
4. After explicit release approval, deploy the reviewed admin page, both JavaScript files, the settlement helper, CSS, and their reviewed accounting dependencies together. No feature-specific database migration is needed. Test scripts/docs need not be placed on the public server.
5. The updated script/CSS query versions invalidate browser cache. Refresh the page and verify Available-tab selection and modal details with staging data before real use.

## Rollback instructions

Restore the previously deployed admin page, JavaScript and CSS together, and remove the newly deployed `provider_bulk_settlement.php` and `provider-bulk-settlement.js` only after restoring the page that references them. Retain all financial records, audit entries, references and batch notes.

There is no schema migration to reverse. **Do not unset settled statuses or delete batch records as a code rollback**: they represent manual transfers already recorded and remain compatible with the prior ledger's per-booking status/amount/reference fields. Any financial correction must use the existing reconciliation process.
