# V16 Permissions Matrix

## Model

V16 will use deny-by-default role-based access control with policy checks at application use-case boundaries. Authentication proves identity; authorization separately evaluates action, resource scope, ownership, and current state. UI visibility is never an authorization control.

Phase 2 implements these permissions as canonical rows and resolves grants through role-permission joins. Role names are labels, not authorization checks. The server derives permission keys and authority ceilings from stored assignments.

| Capability | Platform administrator | Operations manager | Operations user | Finance user | Read-only auditor | Unauthenticated |
|---|---:|---:|---:|---:|---:|---:|
| View health/status | Yes | Yes | Yes | Yes | Yes | No |
| Run fresh installer | Deployment process only | No | No | No | No | No |
| Run migrations | Deployment process only | No | No | No | No | No |
| View application logs | Restricted operations process | No | No | No | Read-only by approval | No |
| Manage identities/roles | Future policy | No | No | No | No | No |

Canonical authority levels are System Administrator 100, Organisation Administrator 80, Manager 60, Agent 40, and Read Only 10. Assignment requires `roles.manage`; the target user and any organisation-owned role must match the actor's organisation; a role at or above the actor's ceiling cannot be granted; self-assignment is rejected. System roles are protected and may not be deleted through application workflows.

Record visibility is granted exclusively by `scope.own`, `scope.location`, or `scope.organisation`. Runtime code does not inspect role names or authority levels to determine data scope. Authority levels remain limited to the separate role-assignment ceiling.

Canonical role seeds are convenient defaults: System Administrator and Organisation Administrator receive organisation scope, Manager and Read Only receive location scope, and Agent receives own/assigned scope. These grants may be changed through governed role-permission configuration. A custom role has exactly the scope capabilities assigned to it, and a role named Manager has no implicit scope.

## Enforcement requirements

- Every protected use case names a permission and supports resource scoping.
- Absence of a grant is a denial; conflicting grants resolve to the narrower scope.
- Privileged changes require audit records containing actor, action, target, result, and UTC time.
- Service and deployment identities are not interactive user roles.
- Tests must cover anonymous, insufficient-role, wrong-scope, stale-state, and permitted paths.
- Permission names and role mappings will be version-controlled configuration or canonical data, never scattered conditionals.
# Phase 3 quote capabilities

Quote operations use `quotes.view`, `quotes.create`, `quotes.edit`, `quotes.send`, `quotes.accept`, `quotes.decline`, `quotes.adjust`, `quotes.compliance`, and `quotes.convert_ready`. Capabilities are independent of role labels and always combine with `scope.own`, `scope.location`, or `scope.organisation`. Cross-organisation access is denied first. Phase 2 assignment ceilings prevent self-granting.
