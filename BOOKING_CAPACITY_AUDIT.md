# Booking capacity and fee audit — 17 October 2026

Status: local capacity, fee and guest-form changes are implemented and checked with offline fixtures. The user has confirmed adults are aged 14+ and children are aged 0–13. Search and tour booking forms now use that classification. Each new tour booking collects one Children (0–13) count. The former 0–7 split and additional child field have been removed. Complete the manual migration and staging checks below before deployment.

## Findings and traced flows

Tourist boat/guide selections enter through public/hotel_resorts.php, public/search_results.php and public/service_details.php (js/service_details.js), then public/tour_booking.php. Package links also carry guest selections through public/package_details.php. Tour checkout posts to payments/create-booking-checkout.php, saves a price snapshot and payment draft, then PayMongo reconciliation calls BookingCheckoutService::submitPaidDraft inside its transaction. Saved receipt components are read by php/payment_receipt_breakdown.php; historical component recovery is deliberately unchanged.

Admin quotes and creation are handled by admin/adbookings.php and php/walkin_booking_helper.php. Operator/opbookings.php creates package bookings, with a separate package/payment workflow. php/submit_booking.php is already disabled over HTTP (410); its historical client-trusted code was not re-enabled or rewritten. Hotel age and room rules were inspected and left intact.

Root causes:

- Tourist boat charges used ceil(total guests / 8), but additional boats were optional preferences: no combined actual-capacity check, no additional-resource locking or draft reservation, and no allocation persistence at verified-payment finalization.
- Admin multiple-boat storage already existed as booking_resource_boats. Its capacity calculation could exceed the required eight-passenger operational limit when a vessel advertised more than eight seats.
- Guides used a single guide_id and one guide charge, regardless of passenger count. No additional-guide storage or selection path was found. The current guide forms/catalog do not expose configurable individual capacities; eight passengers is enforced.
- Tourist/admin environmental fees already used adult counts, but the operator-created package form accepted a manually entered environmental amount. That entry point now derives the fee from configured rates and adults.
- Rescheduling checked only the primary resource. It now checks all assigned boats and guides and pending checkout holds, excluding the booking being rescheduled.
- Child ages previously affected entrance fees independently of environmental fees: children aged 0–7 receive the existing entrance exemption. Following the user’s latest correction, new tour/admin forms collect one Children (0–13) count only. New per-head entrance charges use every passenger; environmental charges use adults only. No child is assigned an age. Hotel age collection remains separate.

## Rules implemented

- Passenger capacity uses adults + children. Minimum resources = ceil(pax / 8).
- Effective boat capacity = min(configured capacity, 8), with invalid/nonpositive capacities rejected. Smaller vessels can require more than the minimum number of boats.
- Boat and guide selections must identify existing resources and contain no duplicates within each resource type. Insufficient boat seats or guide counts are rejected on the server. Redundant extra boats and excess guides are rejected to avoid accidental extra charges.
- Tourist selection now includes mandatory primary resources and additional boats/guides, including optional boat/guide services. The resource controls recalculate when guest counts change and remove redundant selections after a decrease. The existing primary selection is retained; the former unspecified-preference option becomes a selection prompt because explicit assignments are necessary to enforce capacity and availability.
- Admin selection retains its existing boat controls and adds additional available guides. Availability covers the full inclusive date range. Guest decreases remove excess selections; unavailable guide selections are cleared.
- Every selected resource is locked in a consistent order and checked before a tourist draft or admin booking is committed. New checkout drafts reserve primary, additional and add-on resources. Verified payment saves allocations in the reconciliation transaction, after the existing idempotency guard.
- Environmental fee = eligible adult count × the existing configured category rate, rounded to two decimals. php/additional_fees_helper.php remains the source of rates and existing fallback configuration. No new fee amounts were introduced. Operator environmental fees are now calculated, read-only in the form, and recomputed server-side; a forged environmental amount is ignored.
- Each selected boat is charged the configured boat rate once. Each guide is charged the configured same-day or overnight rate once, according to the user's clarification. Overnight charges are not multiplied again by nights. Docking charges use the actual selected boat count.
- New tour bookings have no separate 0–7 age band or exemption-count input. Environmental fees apply only to adults (14+); children (0–13) are excluded from environmental fees and included in pax, capacity and configured per-head entrance charges. The backend ignores stale age-split inputs, calculates charges independently and records the entrance passenger count in the existing JSON payload. Saved historical drafts/receipts retain their original age-dependent prices. Hotel-specific rules, package fares, configured fee rates, deposits, balance handling and statuses remain intact.
- New tourist price snapshots retain resource quantities. Historical drafts and bookings are not repriced or backfilled. Old additional preferences are not retroactively treated as reservations.

## Files changed in this task

| File | Purpose |
| --- | --- |
| php/booking_capacity_helper.php (new) | Shared passenger-capacity, unique-ID, environmental-fee and storage checks. |
| js/booking-resource-picker.js (new) | Tourist additional-resource controls and capacity validation. |
| public/tour_booking.php | Resource picker, boat/guide charge quantities, combined availability calendars, complete selection summary and cent-rounded deposit preview. Corrected misleading environmental-fee helper text. |
| payments/create-booking-checkout.php | Authoritative resource validation, configured charges, full resource locks/availability checks and versioned allocation payloads. |
| payments/BookingCheckoutService.php | Persist allocations after verified payment inside the existing transaction/idempotency guard. |
| php/tour_resource_availability_helper.php | Include new draft allocations, confirmed additional guides, existing extra boats and add-on resources; load complete assignments for rescheduling. |
| php/respond_provider_cancellation.php | Lock and check every assigned resource on rescheduling; preserve existing financial records and refund policy. |
| php/walkin_booking_helper.php | Shared boat limit, guide count/availability/pricing and adult-only configured fees in admin quotes. |
| admin/adbookings.php | Admin guide controls, guide locks and assignment inserts, capped boat UI capacity and full resource review. |
| operator/opbookings.php | Configured environmental category selection and adult-only fee recomputation for new package bookings. |
| booking_resource_guides_migration.sql (new) | Manual additive guide allocation table; also ensures the already-used boat allocation table exists. |
| scripts/booking_capacity_test.php (new) | Isolated real checkout calculations, admin/operator fee checks, reservation SQL and paid-draft finalization. |
| scripts/booking_resource_picker_test.cjs (new) | Tourist selection behavior, actual admin guide control logic and actual frontend pricing parity. |
| scripts/walkin_boat_selection_test.cjs | Update mixed-capacity scenario to honor the eight-passenger maximum. |
| scripts/payment_receipt_breakdown_test.php | Update checkout extraction boundary and guide quantity fixture. |
| scripts/walkin_booking_test.php | Update MySQL fixture schema and expected two-guide prices; not executed in this session. |
| public/hotel_resorts.php | Count-only tour search, compact Adults (14+ years)/Children (0–13 years) labels, no obsolete classification notice; hotel tab isolated. |
| styles/hotel_resorts.css | Aligned label/counter columns, uniform buttons and spacing, single-line range labels. |
| scripts/tour_search_guest_picker_test.cjs (new) | Tour search counts, labels, outgoing parameters and hotel isolation. |
| php/payment_receipt_breakdown.php | Recover full-pax entrance charges for new receipts; preserve legacy age-dependent charges in historical receipts. |
| BOOKING_CAPACITY_AUDIT.md (new) | This report. |

Other pre-existing modified/untracked files were preserved. Changes to already-modified tour/payment files were added on top of their existing working contents.

## Database and payment safety

The supplied migration only creates missing allocation tables. Existing booking columns and primary boat_id/guide_id relationships remain in place. There is no backfill, destructive SQL, booking cleanup or historical price update. No migration was run, and no application database was accessed during verification. The new tourist checkout checks allocation-table readiness before collecting payment and reports a booking-office message if storage is absent.

PayMongo session creation, reconciliation, verified amounts, deposit percentages, balance credits, transaction records and refund handling retain their existing implementation. The necessary payment-related changes are resource quantities in the server-derived total, matching saved breakdowns and allocation persistence at finalization.

## Actual verification

Passed locally:

- PHP lint for all 12 PHP files modified/added in this task, and parsing of all edited inline form scripts and the new JavaScript module.
- scripts/booking_capacity_test.php: real calculation blocks and actual availability SQL on in-memory SQLite; 1 adult, exactly 8, 9, 16 and 17 passengers; two/three boats and guides; smaller/larger vessels; adult-only configured environmental fees; entrance exemption preservation; admin/tourist price parity; operator forged environmental amounts; same-day/overnight rates; insufficient/duplicate resources; forged client totals; inclusive pending/confirmed resource conflicts; cancellation release; reschedule assignment lookup/self-exclusion; actual paid-draft allocation persistence and duplicate-event idempotence; 20% deposit/balance preservation. It also executes the existing PayMongo mode fixture suite (222 checks).
- scripts/booking_resource_picker_test.cjs: count increases/decreases, removing children, one/two/three resources, lower boat capacity, cap of eight, incomplete selections, boat/guide add-ons, package isolation, actual admin guide availability logic and actual frontend totals matching PHP fixture totals.
- scripts/walkin_boat_selection_test.cjs.
- scripts/payment_receipt_breakdown_test.php: historical receipts, snapshots, changed rates, existing adjustments and child entrance exemptions.
- scripts/paymongo_return_fixture_test.php: 222 mode checks and 79 return/reconciliation checks, all offline.
- scripts/walkin_payment_acceptance_test.php.
- scripts/operator_payment_flow_test.cjs.
- scripts/walkin_payment_collection_test.cjs.
- scripts/walkin_payment_flow_test.cjs.
- git diff --check.

Not performed: browser interactions or visual/responsive QA; authenticated end-to-end HTTP bookings; actual MySQL migration/queries/concurrent named-lock contention; hosted PayMongo sandbox/live checkout; production testing. The SQLite fixtures execute application business logic and SQL, with MySQL-specific setup adapted for SQLite. They do not establish production correctness. scripts/walkin_booking_test.php was updated but not run because it bootstraps the application database.

## Confirmed classification and form follow-up

The user corrected the adult threshold to 14 years and above. All recently changed tour search, tourist booking, admin booking and operator package forms display Adults (14+ years) and Children (0–13 years). The old age-gap notice and stacked range subtext were removed from tour search. The search rows now use a fixed counter column, evenly sized buttons, centered tabular counts, consistent row padding and separators.

Tourist/admin forms now request only Adults (14+ years) and Children (0–13 years), with no individual ages, second Children field or 0–7 count. The environmental fee is adult count times the configured rate. Total pax is adults plus children; every passenger counts toward boat/guide capacity and configured per-head entrance charges. Operator forms already use one child count and retain the same adult-only environmental calculation. Hotel-specific rules remain unchanged.

Passed follow-up checks: single Children field in tourist/admin forms; stale child-age/exemption inputs cannot alter new checkout prices; frontend/backend price parity; adult-only environmental fees with children present; full-pax entrance charges; resource count changes; actual paid-draft replay; historical receipt exemptions and new full-pax receipt recovery; all relevant PHP lint and inline JavaScript parsing; tour search and boat-selection regressions; git diff --check. The capacity suite also runs the existing 222 offline PayMongo mode checks. No application database, live payment flow or production data was accessed. Browser visual verification remains unperformed. The MySQL walk-in fixture was updated for the new expected totals but was not run because it bootstraps the application database.

## Deployment steps

1. Review the complete change set, including pre-existing edits, and back up the database. On staging, manually apply booking_resource_guides_migration.sql before enabling the new checkout/form files together. No automated deployment or application database migration has occurred.
2. Verify tourist, admin and operator bookings; the corrected age labels; tour search and hotel-tab switching; child/exemption count changes; insufficient/unavailable resources; same-day and overnight selections; receipts; 20% and full payments; duplicate webhooks; balances; cancellation/rescheduling; simultaneous attempts for the same additional resource; and existing paid/historical bookings.
3. Verify desktop/mobile layout and real PayMongo test flows before production rollout. Existing unversioned pending drafts retain their original saved prices and resource semantics. No production or live-payment behavior was verified in this session.

## Guide-capacity verification follow-up

Confirmed that guide-only bookings and the optional tour-guide service on boat bookings use ceil((adults + children) / 8). Checked 8, 9, 16 and 17 pax for the optional guide count, and 8, 9 and 17 pax for guide-only server pricing. Boat add-ons save one guide charge row with the required quantity; the selected same-day/overnight rate applies once per guide. Removing the add-on removes its guide requirement. Insufficient guide selections are rejected.

Found and removed two obsolete references to acknowledgedBoatCount in the tourist step-validation and submit handlers. That variable belonged to the replaced boat-capacity dialog and could cause a ReferenceError when advancing/submitting boat bookings. The current resource-picker validation remains authoritative. Added fixtures executing the actual navigation and submission handlers: missing guide selections block progression; a valid 10-pax boat booking reaches mocked checkout without the JavaScript error.

Passed: scripts/booking_resource_picker_test.cjs, scripts/booking_capacity_test.php (including the existing 222 offline PayMongo checks), PHP lint, inline JavaScript parsing and targeted git diff --check. These are offline fixtures with mocked navigation and in-memory SQLite, not a browser booking or live payment test.
