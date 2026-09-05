# V16 State Transitions

## Policy

Business state changes will be explicit transitions, not arbitrary status updates. Each transition must declare its source states, target state, permission, preconditions, side effects, audit event, and concurrency behaviour. Invalid transitions fail without partial side effects.

## Foundation lifecycle

| From | Event | To | Guard | Recorded result |
|---|---|---|---|---|
| Empty database | Run installer | Foundation installed | Local/test, or correct staging/production confirmation; zero existing tables | Canonical tables plus installation metadata and execution audit |
| Foundation installed | Run installer | Rejected | Database is not empty | Error log; schema unchanged |
| Installed schema | Apply pending migration | Upgraded schema | Migration metadata exists and file was not applied | Migration name and UTC application time |
| Installed schema | Re-run migrations | Same schema | All files already recorded | No-op |

Every attempted installer or migration command also transitions its execution record from `started` to exactly one terminal command result: `succeeded`, `failed`, or `denied`. Individual schema operations record `applying`, then `applied` or `failed`.

## Future transition contract

Feature transition tables must be added before implementing their workflows. State comparisons and writes should occur within one transaction, use optimistic versioning or row locks where appropriate, emit audit events only after successful persistence, and make external side effects retry-safe through an outbox or equivalent mechanism.

## Phase 2 enquiry lifecycle

The live workspace contains only `New`, `Contacted`, and `Quoted`. `Booked`, `Lost`, and `Closed` are archived/completed states.

| From | Allowed targets |
|---|---|
| New | Contacted, Lost, Closed |
| Contacted | New, Quoted, Lost, Closed |
| Quoted | Contacted, Booked, Lost, Closed |
| Booked | None |
| Lost | Contacted, Closed |
| Closed | Contacted |

Same-state updates are idempotent. Status writes use the observed source status in the update predicate to detect concurrent changes. `Quoted` is a state placeholder only and has no quote relation in Phase 2.

Tasks allow `Open`, `In Progress`, `Completed`, and `Cancelled`. Completion records `completed_at_utc`; completed/cancelled operational history is not deleted.
# Phase 3 quote transitions

Allowed transitions are Draft → Ready or Superseded; Ready → Draft, Sent or Superseded; Sent → Accepted, Declined, Expired or Superseded; and Accepted → Converted. Declined, Expired, Superseded and Converted are terminal. Ready/Sent run readiness and compliance checks; Sent requires immutable proposal delivery evidence. Post-send edits require a numbered Draft revision. Accepted enables a handoff but never creates a booking or marks an enquiry Booked.
