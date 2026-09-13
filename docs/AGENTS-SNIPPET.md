# AGENTS.md Snippet — steering agents onto Continuum

Two variants below, same protocol. Replace `PROJECT-ID` with a short
scope name for your project. Continuum = shared coordination board
(what happens next); your long-term memory server = what is permanently
true.

## Compact (recommended)

```markdown
## Coordination (Continuum MCP)

Use Continuum (board scope `PROJECT-ID`) for cross-agent work state and
handoffs; durable outcomes go to long-term memory via promote_to_memory.
Every write is attributed to your agent identity and logged.

Start of session: `agent_heartbeat` → `context_pack(scope:"PROJECT-ID")`
→ `message_inbox_pull`. Use the pack; don't raw-scan.

Tasks: `task_list(scope:"PROJECT-ID", status:"pending")`, claim with
`task_claim` (atomic — a conflict means another agent owns it), advance
with `task_update_status`. NEVER stop while claimed: call `task_handoff`
(summary, nextSteps, blockers) to release for the next agent.

Shared state: `blackboard_write(key, value, scope:"PROJECT-ID")`.
Resources: `advisory_lock_acquire/release` — advisory, TTL-bound;
respect holdings, check first. Talk: `message_send`; broadcast is
last resort.

Ending a session: write your delta to the board, hand off any claims,
promote durable facts out.
```

## Full version (when the project needs the reasoning inline)

```markdown
## Multi-Agent Coordination (Continuum)

Shared coordination blackboard for this project; scope `PROJECT-ID`.
The long-term memory server keeps what is true; Continuum keeps what
happens next: plans in motion, claims, locks, handoffs, audit trail.

### Session start
1. `agent_heartbeat` (with `workingOn` when known).
2. `context_pack(scope:"PROJECT-ID")` BEFORE raw scans — curated,
   budgeted; add `query:` for relevance ranking. Pull
   `message_inbox_pull` for anything addressed to you.

### Tasks
- Claim with `task_claim(taskId)`; claims are atomic (MVCC) — a
  conflict means another agent owns the task.
- `task_update_status(taskId, status, note)` through:
  pending → claimed → in_progress → (blocked|review) → done/cancelled.
- Mid-task stop: `task_handoff(taskId, summary, nextSteps, blockers)`.
  Never leave a task claimed.

### Shared state and coordination
- `blackboard_write(key, value, scope:"PROJECT-ID")`; reserve `global`
  scope for board-wide facts only.
- `advisory_lock_acquire(name, ttlSeconds)` / `advisory_lock_release`:
  cooperative, TTL-expiring; never force past another agent's lock.
- `message_send(to, body)` for directed notes; `message_broadcast` only
  for genuinely board-wide announcements.

### Session end
Write the current delta to the board (scope `PROJECT-ID`), hand off
every claimed task, and push outcomes to long-term memory with
`promote_to_memory(entity, facts)`. Audit with `coordination_event_log()`
or `board_status()`.
```

## Design rationale (for rules maintainers)

- **context_pack first** — raw scans waste tokens on cold state; the
  pack is pre-ranked and budget-limited.
- **Atomic claims** — a raced claim fails clean; no client-side leasing
  needed.
- **Handoff over abandon** — Continuum has no dead-worker signal other
  than stale claims; handoff is the structured answer.
- **Advisory locks** — they hold nothing physically; the protocol relies
  on agents respecting holdings, so the rule text must say so.
- **promote_to_memory boundary** — keeps long-term memory free of agent
  churn; only outcomes cross over.
