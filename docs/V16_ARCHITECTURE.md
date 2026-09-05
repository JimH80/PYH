# V16 Architecture

## Purpose

V16 is a clean PHP 8.3 rebuild. Earlier versions may later inform observable behaviour, but do not define this codebase's structure. Phase 1 deliberately contains no customer, enquiry, quote, booking, finance, cruise, or WordPress features.

## Shape

- `public/` is the only web document root and contains the front controller.
- `app/` composes infrastructure and exposes the guarded console entry point.
- `src/` contains PSR-4 classes grouped by responsibility, independent of delivery mechanism.
- `config/` maps environment variables into typed runtime configuration.
- `database/schema/` is the authoritative definition for an empty database.
- `database/migrations/` contains forward-only upgrades for existing installations.
- `storage/` contains replaceable runtime logs and caches, never source or secrets.
- `tests/` separates fast unit checks from database, workflow, and security suites.

## Runtime boundaries

Dependencies point inward: entry points compose configuration, logging, and database infrastructure; domain modules added later must not depend on `public/` or global state. PDO is configured for exceptions, native prepared statements, and associative results. Times stored by the platform use UTC; presentation timezone conversion belongs at the edge.

## Security baseline

Secrets come from process environment or an ignored local `.env`. Production error details are suppressed. Logs are structured JSON and must not receive credentials, tokens, or unnecessary personal data. Database accounts should be least-privilege and distinct per environment. The web server must expose only `public/`.

## Future module rule

Each feature should introduce explicit application use cases, domain rules, and infrastructure adapters behind narrow interfaces. Cross-feature access must occur through declared services rather than shared mutable tables or includes.
# Phase 3 extension

The quote engine extends the locked Phase 1/2 foundation. `QuoteService` owns scoped mutation, reconciliation, readiness, compliance and transitions. `ProposalService` owns allowlisted snapshots, delivery evidence, decisions and the handoff boundary. Domain rules remain independent of PDO and HTTP. See `V16_PHASE3_QUOTE_ENGINE.md`.
