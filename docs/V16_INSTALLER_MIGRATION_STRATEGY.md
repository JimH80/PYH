# V16 Installer and Migration Strategy

## Two distinct paths

The canonical schema is the single reviewed snapshot for a completely empty MySQL 8 database. It must include every current table, constraint, index, canonical reference row, charset, and collation needed by a fresh installation. It must not be reconstructed by replaying historical migrations.

Migrations are forward-only changes for databases that already have an installed schema. Every migration has a timestamped immutable filename and is recorded in `schema_migrations` after successful execution. Applied migration files are never edited; corrections use a new migration.

## Installation flow

1. Provision an empty database and a least-privilege deployment identity outside the application.
2. Supply settings through environment variables; never commit a populated `.env`.
3. Confirm `APP_ENV` is correct. Local/test runs need no override; staging requires the short-lived `CONFIRM_STAGING_DATABASE_CHANGES=true` confirmation; production is denied unless the deployment process explicitly sets `ALLOW_PRODUCTION_DATABASE_CHANGES=true`.
4. Run `php app/console.php install`. The installer refuses any database containing tables.
5. Run integration and smoke checks, then remove any staging or production confirmation flag.

## Upgrade flow

Back up and validate restore procedures, deploy compatible application code, set the environment-specific confirmation only for the migration process, run `php app/console.php migrate`, verify schema and application health, then remove the confirmation. Destructive or long-running changes require an individually reviewed expand/migrate/contract plan.

Unknown environment names fail closed. A production override does not authorize staging and a staging confirmation does not authorize production. Every command attempt writes structured audit events containing environment, canonical or migration identifier, UTC timestamp, and result. Per-file migrations additionally record applying, applied, or failed; denied and connection-level failures are recorded by the command boundary. Audit logs must be retained and access-controlled by the deployment environment.

## MySQL conventions

Use InnoDB, `utf8mb4`, explicit `utf8mb4_0900_ai_ci` collation, UTC timestamps, named constraints/indexes, unsigned numeric surrogate keys where appropriate, and explicit nullability. Avoid database-specific business logic in triggers unless an architecture decision records why it is unavoidable.

MySQL DDL may auto-commit, so migration safety cannot assume transaction rollback. Every change needs pre-production rehearsal, idempotency consideration, and a documented recovery path.
