# V16 Canonical Schema Audit

## Scope and result

The Phase 1 canonical schema contains only `schema_migrations` and `installation_metadata`. No business tables were introduced. The schema is ordered safely for an empty MySQL 8 database and has no unresolved dependency issue.

| Review area | Finding |
|---|---|
| Foreign-key order/dependencies | Neither table has a foreign key. Creation order is therefore dependency-safe. Future referenced/parent tables must precede referencing/child tables. |
| Foreign-key indexes | Not applicable in Phase 1. MySQL requires an index on referenced keys and automatically requires/indexes referencing columns; V16 will declare intentional, named indexes explicitly. |
| Uniqueness | `schema_migrations.migration` is a primary key, preventing duplicate application records. `installation_metadata.id` is the primary key and `schema_version` is unique. |
| Timestamps/timezone | Both timestamps use microsecond precision and UTC naming. Every application PDO connection explicitly sets the MySQL session to `+00:00`; migration time defaults to `CURRENT_TIMESTAMP(6)` and installation code writes `UTC_TIMESTAMP(6)`. Infrastructure UTC remains recommended but is not assumed. |
| Delete/update actions | No foreign keys exist, so cascade/restrict actions are not applicable. Future constraints must declare intentional `ON DELETE` and `ON UPDATE` behaviour; implicit destructive cascades are disallowed. |
| MySQL 8 compatibility | InnoDB, `utf8mb4`, `utf8mb4_0900_ai_ci`, `TIMESTAMP(6)`, unsigned auto-increment keys, and named unique keys are MySQL 8-compatible. The collation intentionally excludes MySQL 5.7/MariaDB compatibility. |
| Empty-install ordering | Metadata tables are independent and created before installer metadata is inserted. The installer first verifies that `SHOW TABLES` returns no tables. |

## Residual verification requirement

This is a source-level audit only. Approval still requires executing the canonical schema against an isolated empty MySQL 8 instance, running it through the installer, verifying a second install is rejected, checking recorded UTC values, and exercising a real migration. MySQL DDL auto-commit behaviour means a partially failed schema needs an operational recovery procedure rather than assumed transaction rollback.
