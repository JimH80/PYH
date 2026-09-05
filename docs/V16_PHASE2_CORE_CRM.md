# V16 Phase 2 Core CRM Architecture

## Modules and boundaries

Phase 2 adds organisation, location, user, role, consultant profile, customer, traveller, enquiry, task, communication, audit, and search capabilities. It intentionally excludes quotes, bookings, finance, cancellation, cruise, Travel Data Hub, WordPress, Research & Enrich, Ready Deals, and Marketing Studio.

The `Domain` layer owns enquiry states/reference generation and duplicate-warning rules. `Application` services coordinate PDO persistence, permissions, scope checks, transactions, and audit events. `Security` owns actors, authentication sessions, CSRF, password hashing, output escaping, and permission evaluation. `Web` is a server-rendered delivery adapter; it is not trusted to enforce business rules by itself.

## Ownership and access

Every operational aggregate carries an organisation boundary. Location/agent-owned records are filtered in queries and rechecked by services before mutation. Scope is derived only from the explicit `scope.own`, `scope.location`, and `scope.organisation` capabilities loaded from role-permission grants. Role names and authority levels never determine visibility. Missing and forbidden scoped records use the same `Record not found` result to avoid existence disclosure.

Customers are archived, not deleted. Travellers remain anchored to their customer identity. Booked, Lost, and Closed enquiries are excluded from the live workspace. Assignment changes write immutable history inside the same transaction as the enquiry update. Completed tasks and communication logs remain operational history.

## Authentication and request security

Passwords use PHP's current `PASSWORD_DEFAULT`; plaintext is never persisted or audited. Login accepts only active users and regenerates the session identifier. Sessions use HttpOnly and SameSite cookies, Secure cookies outside local/test, and server-side expiry. Every POST route validates a session-bound CSRF token. All rendered values pass through context-appropriate HTML escaping.

Password-reset delivery is deferred. A later design must use single-use, short-lived hashed tokens, generic responses, rate limiting, session invalidation, and an audited completion event.

Missing or invalid CSRF credentials raise a dedicated security rejection caught at the public entry boundary. HTML clients receive a generic HTTP 403 page without exception, stack, SQL, path, secret, or token detail. The security log records method and path only; token values are never logged.

## Search

Global search uses parameterised queries, escaped wildcard input, a minimum query length, and a maximum of 50 results. Each result type is included only when the actor has its view permission, and customer/enquiry results apply agent/location scope in SQL. Inaccessible records are omitted rather than described.

## Audit

Material actions record actor, environment, action, entity type/id, UTC timestamp, request identifier where supplied, and redacted before/after summaries. Keys resembling passwords, tokens, credentials, or secrets are redacted before JSON storage.
