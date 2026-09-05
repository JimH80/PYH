# V16 Test Strategy

## Tooling

PHPUnit 11 is the test runner and PHPStan 2 at level 8 is the initial static analyser. Composer provides reproducible development dependencies. Tests use PSR-4 namespaces and share only the application bootstrap.

## Suites

- `tests/unit/`: isolated value, policy, parser, and domain-rule tests; no network or database.
- `tests/integration/`: repository and schema behaviour against a disposable MySQL 8 database identified only by `TEST_DB_*` environment variables.
- `tests/workflow/`: complete use-case orchestration and state-transition tests across module boundaries.
- `tests/security/`: authorization denial paths, input handling, secret leakage, session protections, and regression cases.

Environment-policy tests must prove local/test allow-by-default behaviour, separate staging and production confirmations, cross-flag isolation, and fail-closed handling for unknown environments. Audit tests must verify environment, identifier, UTC timestamp, and result fields.

## Database discipline

Integration tests must never reuse development, staging, or production databases. CI provisions a disposable MySQL 8 instance, installs the canonical schema from empty, exercises migrations from supported baselines, and destroys the instance. Tests must fail closed when an unsafe database target is detected.

## Quality gates

Before merge: syntax lint all PHP files, run PHPUnit, run PHPStan, validate the canonical schema on MySQL 8, scan tracked content for secrets, and confirm a clean fresh install. New behaviour requires tests at the lowest useful layer plus workflow/security coverage where state or permission boundaries change.

Phase 2 destructive integration workflows are additionally locked to `APP_ENV=test` and the exact disposable database name `pyh_v16_phase2_test`. Any other name or environment is skipped/refused. The gate recreates only that database, installs the current canonical schema, checks MySQL foreign-key metadata, runs the workflow/security suites, and separately proves the Phase 1-to-2 migration path.
# Phase 3 execution gate

Phase 3 adds unit coverage for state, references, integer-minor-unit money, optional extras, adjustments, readiness, compliance, rendering and order. Database workflows cover structured detail, pricing, proposal/delivery/decision persistence, immutable revisions, handoff and audit. Security covers capabilities, IDOR scope, parameterisation, CSRF 403, status tampering, XSS and disclosure. Destructive execution is limited to `APP_ENV=test`, a local host and exact `pyh_v16_phase3_test`.
