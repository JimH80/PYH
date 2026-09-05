# Phase 4A.2 booking core

`QuoteBookingConversionService::convert` requires bookings.create, bookings.view and quotes.view. ScopeEvaluator validates the quote, customer and linked enquiry. A transaction locks the quote, handoff, accepted proposal and linked records; verifies accepted version/readiness; inserts booking and traveller snapshots; converts the quote; books the enquiry; and inserts audit events before commit. Any exception rolls back the entire transaction, including audits. Ordinary QuoteService transitions cannot independently mark a quote Converted.

The unique nullable bookings.quote_booking_handoff_id key and the quote row lock prevent duplicate conversion. The existing BookingReferenceGenerator format is unchanged. The conversion retries reference-key collisions up to five times, without treating other constraint failures as reference collisions.

New proposals include structured traveller names, DOB, classification, order, master ID and lead flag in their stored snapshot. Conversion compares handoff customer evidence with the accepted proposal and copies the stored values, never rereading names from traveller masters. Historical handoffs containing only display names fail with a controlled validation response; they need a new accepted proposal with structured identity evidence. This slice does not guess names or rewrite historical proposal/handoff evidence.

Accepted customer pricing is copied in integer minor units. Commercial totals include only arrangements present in the accepted proposal. Distinct supplier names and references are combined with ` / `; a summary exceeding the existing column capacity is rejected instead of truncated. No booking elements, payments or commission entries are created by conversion.

BookingService exposes list, load, travellers, totals, lineage and update. Read operations require bookings.view and central capability scope. Lineage is independently checked against quote/enquiry permissions and scope. Core updates require bookings.edit, reject unsupported fields, validate dates, and use BookingStateMachine for Booked -> Amended. Commercial edits and reassignment are not exposed. Direct booking creation and its wizard are deferred; the authorised workflow is quote conversion.

Authenticated routes: GET /bookings, GET /booking?id=..., POST /booking, POST /bookings/convert. The Accepted quote screen supplies a ready-handoff action. Mutations use the existing CSRF guard. Missing/inaccessible records use the same 404 response; permissions use 403; state/readiness/duplicate failures use controlled 422 responses.

Schema version: 4.1.0-booking-core. Migration: 20260905_210000_phase4_booking_conversion.sql.

Executable coverage: BookingConversionWorkflowTest (real Phase 3 workflow, rollback after traveller insertion, duplicate and reference-collision handling, scoped access, update restrictions and snapshot independence), BookingReferenceGeneratorTest, and tests/http/booking_core_smoke.py. The HTTP smoke starts a local PHP server and uses only pyh_v16_phase4_test; its synthetic records are disposable. Run with APP_ENV=test, TEST_DB_HOST=127.0.0.1 and TEST_DB_DATABASE=pyh_v16_phase4_test after a current canonical installation.

## Verification

Final complete suite: 103 tests / 1,054 assertions. Protected baseline suite: 96 tests / 946 assertions. PHPStan level 8, Composer strict validation, PHP lint, canonical installation/migration parity, and local HTTP smoke passed. The smoke also verifies identical 404 bodies for inaccessible and missing bookings. Forced failure after traveller insertion leaves no booking/traveller/audit residue; reference collisions retry; duplicate handoffs are rejected by both service and database.

Known compatibility limitation: historical display-name-only handoffs cannot meet the structured identity requirement and are rejected without mutation. No automatic legacy backfill is included. No Critical/High issues were found in the tested slice; this legacy conversion limitation remains a Medium compatibility issue.
