# Phase 4A.3 — Booking operations architecture review

## Implemented boundary

Existing bookings now have a tabbed internal workspace: Overview, Travellers, Holiday, Payments, Documents, Checklist, Finance and Timeline. Authentication, CSRF and controlled 403/404/422 behaviour use the existing application boundary. Services use the existing BookingService and central ScopeEvaluator, never role names for authorization. Missing child IDs and children from another booking are indistinguishable.

BookingOperation provides shared validation, scoped booking locks, parameterized persistence and atomic audit writes. Cohesive services own elements, customer payments, supplier payments, commission, adjustments, documents, checklists, amendments and timeline. CustomerBookingProjection is a separate whitelist boundary, not a customer portal.

## Financial rules

Customer position = core selling price + confirmed elements + Approved adjustment effects + Applied amendment effects − customer Payments + customer Refunds. Supplier payments, supplier cost and commission never enter this calculation. Magnitudes are validated DECIMAL-compatible strings and calculations use integer minor units.

V16 records Hays payment events only. It processes no payment and accepts no card/credential fields. Payment history has no edit/delete service. Refunds cannot exceed net receipts; booking row locks serialize concurrent writers. Corrections use explicit compensating entries. No broad payment scheduling subsystem was added; the existing deposit/final-balance foundation remains the schedule boundary.

Supplier obligations may be core or linked to a same-booking element. Paid evidence cannot be changed through the service. Standard commission uses CommissionSchedule and existing source/instalment uniqueness. For commission 101.01, booked 31 January 2026, travel 15 June 2026: 50.50 is due 7 February and 50.51 is due 7 July. Received entries are locked; manual exceptional Post-Cancellation/Clawback evidence coexists. No cancellation-generated commission or cancellation finalisation exists.

Adjustments use AdjustmentStateMachine. Approval requires bookings.approve_adjustment; self-approval additionally requires bookings.self_approve_adjustment. Both actors are retained, with append-only Submitted/Approved/Rejected/Reversed event evidence. Negative customer totals are rejected.

Amendments preserve JSON before/after core snapshots, directional monetary effect and actor/date evidence. Draft → Pending → Approved/Rejected; Approved → Applied. Applying stale evidence is rejected. Applying changes, updating status and auditing are atomic. Material supplier identity/reference, booked-date and travel-date changes require amendment evidence. Noncommercial internal notes and supplier booking confirmation-reference updates retain the core-edit boundary. Amendment financial effects enter totals once, only when Applied.

## Documents and operations

Documents support a distinct supplier-issued ATOL Certificate type. Upload defaults to Agent only; PDF, PNG, JPEG and plain text are accepted up to 10 MB. Fileinfo validates MIME; bytes are stored under storage/private/booking-documents outside public, with random keys, private permissions and per-booking directories. Metadata persists in MySQL. Authenticated downloads check booking and document scope and force attachment/nosniff responses. Failed metadata/audit registration removes the uploaded file. No public URL or filesystem path appears in the customer projection. Existing external document metadata can still be registered with opaque references; a metadata-only record has no downloadable local bytes until an upload is registered.

BOOKED_DEFAULT instantiation is idempotent. Manual task items, assignment, due dates, notes and completion metadata are supported. Completed task evidence cannot be overwritten. Summary shows completed/total/overdue. Evidence indicators separately expose supplier-reference presence, settled customer balance and ATOL-document presence; these indicators do not pretend a human check happened or silently mark tasks complete.

Customer projection contains booking identity, holiday details, traveller identity snapshots, customer position and Customer visible document descriptors. It excludes storage keys, costs, commission, supplier-sensitive fields, internal notes, adjustment records and audit information. Internal Finance and commercial fields require explicit finance-view authority. Timeline exposes meaningful labels/timestamps and filters financial event categories for ordinary viewers. Search and dashboard booking attention reuse ScopeEvaluator.

## Permissions and migration

Added bookings.finance_view, bookings.finance_manage, bookings.record_payment and bookings.self_approve_adjustment. Administrators and Managers receive finance view/manage and payment recording; Agents receive payment recording; only the System Administrator seed receives self-approval. Runtime checks use capabilities exclusively. Existing adjust/approval/document/checklist/amend permissions remain authoritative.

Schema version: 4.2.0-booking-operations.
Migration: 20260905_220000_phase4_booking_operations_services.sql.
The migration adds permission grants and metadata only; no new tables. Fresh canonical and 4.1.0 upgrade schemas/permission seeds are compared, including rerun idempotency. Composer declares fileinfo for safe MIME detection; no dependency package versions changed.

## Exact source/artifact files

Created:
- src/Application/BookingOperation.php
- src/Application/BookingElementService.php
- src/Application/BookingPaymentService.php
- src/Application/BookingSupplierPaymentService.php
- src/Application/BookingCommissionService.php
- src/Application/BookingAdjustmentService.php
- src/Application/BookingDocumentService.php
- src/Application/BookingChecklistService.php
- src/Application/BookingAmendmentService.php
- src/Application/CustomerBookingProjection.php
- src/Application/BookingTimelineService.php
- src/Web/BookingWorkspace.php
- database/migrations/20260905_220000_phase4_booking_operations_services.sql
- tests/integration/BookingServicesWorkflowTest.php
- tests/http/booking_operations_smoke.py
- docs/PHASE_4A_3_BOOKING_OPERATIONS.md

Changed:
- .gitignore
- composer.json
- composer.lock
- app/console.php
- database/schema/001_canonical.sql
- src/Installation/Installer.php
- src/Application/BookingService.php
- src/Application/SearchService.php
- src/Application/DashboardService.php
- src/Web/WebApplication.php
- public/assets/app.css
- tests/integration/CanonicalSchemaIntegrityTest.php
- tests/integration/BookingsPersistenceTest.php
- tests/integration/BookingTravellersPersistenceTest.php
- tests/integration/BookingElementsPersistenceTest.php
- tests/integration/BookingPaymentsPersistenceTest.php
- tests/integration/CommissionLedgerPersistenceTest.php
- tests/integration/FinancialAdjustmentsPersistenceTest.php
- tests/integration/BookingOperationsPersistenceTest.php

Generated test logs/caches and disposable database fixtures are not source changes.

## Routes

GET /bookings; GET /booking?id=...&tab=...; GET /booking/document?booking_id=...&id=....
Existing POST /booking core edit remains. Focused POST /booking/elements, /booking/payments, /booking/supplier-payments, /booking/commission, /booking/adjustments, /booking/documents, /booking/checklist, /booking/amendments each dispatch to their own service. All use authentication, CSRF, capability, scope and input checks.

## Review limits

No cancellation finalisation, automatic cancellation clawbacks, lifecycle automation, public portal, external payment processing, supplier API or Phase 5 work is included. No staging, production or V15 database was used.

The inherited Phase 4A.2 Medium compatibility limitation remains: historical display-name-only handoffs cannot be converted without structured accepted traveller evidence. No new Critical/High issue was found in the exercised Phase 4A.3 scope. This report is evidence for architecture review, not architecture approval.

## Final verification evidence

- Complete PHPUnit: 110 tests / 1,338 assertions passed; no skips or failures.
- Protected Phase 1–4A.2 suite: 103 tests / 1,054 assertions passed.
- Seven new service/workflow/security/upgrade tests cover supplier invariance, payments/refunds, immutable received commission, explicit self-approval, append-only decisions, private projections, child-record IDOR, search/dashboard scope, and complete schema/permission upgrade parity.
- Forced audit failures roll back adjustment submission/decision plus history, both standard commission rows, and amendment application plus booking changes.
- PHPStan level 8: no errors. Composer strict validation and lock/platform verification: passed. Project PHP lint: passed.
- Fresh canonical install, 4.1.0 upgrade, all-table DDL parity, permission seed parity and migration rerun idempotency: passed.
- Original booking HTTP smoke and expanded operations HTTP smoke: passed. The latter covers all eight workspace tabs/routes, CSRF on every mutation route, finance denial, indistinguishable 404 responses, 422 validation, HTML escaping, and private upload/authenticated download with attachment/nosniff headers.
- Credential/private-key pattern scan: 131 files, zero candidates. git diff --check: passed.

Defects repaired during implementation: array syntax and PHPDoc type errors; wrong audit timestamp column in timeline; a smoke-fixture role/scope mismatch; finance fields returned by inherited core readers; missing before/after financial audit values; and material booked-date edits bypassing amendment evidence. All final gates were rerun after repairs.
