# Hotel booking final-step layout

Updated locally on 20 October 2026 to match the tour booking review pattern.

The last step displays a Stay overview card with hotel, selected room, dates/nights, guests and child ages/charges, guest contact and payment option. The existing detailed payment breakdown moves to the right-hand selected-stay card. At the existing mobile breakpoint the card moves into step 3 above the acknowledgments and Pay button, using the acknowledgment box as its placement anchor to avoid circular DOM insertion.

The implicit legal sentence is replaced by two required client-side acknowledgments: Privacy Policy/Terms & Conditions, and hotel cancellation policy/child accommodation/optional add-on charges. Existing error rendering, focus, step selection and touched-field validation handle them. The policy reference links to the actual hotel's cancellation-policy block; no unrelated tour environmental/docking fees were added. The full-width button displays Pay plus the current amount due.

Files: public/hotel_booking.php and public/styles/hotel_booking.css. No backend, database schema, payment route, room price, child rate, add-on, deposit or cancellation-policy calculation was changed. Backups: storage/private/hotel-review-layout/before/.

Verification: scripts/hotel_booking_review_test.cjs covers acknowledgment gating, unique summary targets, desktop/mobile placement and Pay label. Existing hotel child UI/parity (164 checks), add-on picker, hotel policy (81 functional checks) and PayMongo mode (222 offline fixture checks) suites passed. PHP syntax and extracted inline JavaScript checks passed; pricing/deposit declarations match the pre-layout backup. No real booking, payment or email was submitted. Full authenticated visual/browser checkout remains a local manual/staging check. Nothing was deployed, committed or pushed.
