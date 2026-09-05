# V16 Phase 3 Quote Engine

## Boundary

Phase 3 owns quotes, ordered proposal components, deterministic pricing, compliance evidence, immutable customer proposal versions, recorded delivery and customer decisions. It stops at `quote_booking_handoffs`: this is an immutable input contract for Phase 4 and is not a booking.

## Aggregate and components

`quotes` is the scoped aggregate root. It belongs to one organisation, customer, location and consultant and may link to an enquiry. `quote_components` contains the stable common arrangement contract and display order. Flights use ordered `quote_flight_sectors`; accommodation and cruises use one-to-one structured detail tables. Other supported types use the common contract until a future domain requires structured fields.

Travellers retain their Phase 2 master reference and a proposal-safe identity snapshot captured when attached. Passport data is outside Phase 3. Ordered components compose package, cruise, cruise-and-stay and multi-centre products without product-specific blobs.

## Pricing

All persisted money is `DECIMAL(13,2)`. PHP calculations convert validated decimal strings to integer minor units and never use binary floating point. The canonical formula is `customer total = selected/included component selling prices + fees/corrections/other adjustments - discounts`. Unselected Optional and Recommended components are excluded and reported separately. Supplier cost and commission are persisted for internal handoff but excluded from the customer snapshot and renderer. Recalculation overwrites derived totals server-side; negative components and totals are rejected.

## Compliance and readiness

Compliance reviews are append-only evidence records with Pass, Warning or Block results, evidence, source, actor and UTC time. This is operational support, not legal certification. Ready and Sent require an accessible customer, lead traveller, valid dates and expiry, included arrangement, reconciled price, and a latest review without a Block. Direct request status values cannot bypass the state machine.

## Proposal evidence and revisions

Proposal generation serialises only allowlisted customer fields and renders safely escaped HTML from the same snapshot. Recording delivery finalises that version and moves Ready to Sent. There is no real delivery-provider integration. A revision moves a Sent aggregate to a new Draft revision while leaving every earlier JSON snapshot, rendered HTML and SHA-256 digest unchanged. PDF generation is deferred; a later PDF adapter must consume the immutable snapshot.

## Handoff contract

Only an Accepted proposal can produce a handoff. It captures the accepted customer snapshot plus internal supplier/cost/commission data, accepted version, agent, compliance evidence, price/deposit/balance, UTC generation time, readiness result and a SHA-256 digest. Phase 4 must consume this contract rather than reinterpret mutable masters.

## Security and UI

Every entry point checks an explicit `quotes.*` capability. Aggregate lookup constrains organisation and applies the Phase 2 capability-driven scope evaluator. PDO prepared statements carry request values. The renderer uses an allowlist and HTML escaping; it has no access to internal notes, commercial fields, audit data, credentials or tokens.

Authenticated routes provide quote list/create, six-section builder, extras/pricing, review/send, proposal preview/history inputs, decisions and handoff generation. Search includes scoped quotes and navigation is capability filtered. Verified sailings, PDF, providers and bookings remain later adapters.
