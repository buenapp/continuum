# AGENTS.md Snippet — steering agents onto Continuum

Paste this block into a project's `AGENTS.md` (or the agent's global
rules) to teach every agent session how to use the Continuum blackboard
for cross-agent handoff, resume, and coordination. Adjust the project
scope name and the notes about which long-term memory server to use.

Replace `PROJECT-ID` with a short scope name (used as the board scope).

---

```markdown
## Multi-Agent Coordination (Continuum)

This project participates in a shared coordination blackboard (the
Continuum MCP server). Heliofane remains the long-term memory; Continuum
owns what happens NEXT — plans in motion, claims, locks, handoffs, and
the append-only audit trail.

Scope for this project: `PROJECT-ID`.

### Session start

1. Call `agent_heartbeat` (with `workingOn` if you already know it).
2. Call `context_pack(scope: "PROJECT-ID")` BEFORE scanning anything
   raw. It is the curated, budgeted view of open work. Add `query:` when
   you need relevance ranking, and check `message_inbox_pull()` for
   anything addressed to you.
3. Only then touch project-local state.

### Working on tasks

- Check `task_list(scope: "PROJECT-ID", status: "pending")` and claim
  work with `task_claim(taskId)` — claims are atomic; a conflict means
  another agent owns it.
- Move work via `task_update_status(taskId, status, note)`. Statuses:
  pending, claimed, in_progress, blocked, review, done, cancelled.
- When you must stop mid-task, NEVER leave it claimed: call
  `task_handoff(taskId, summary, nextSteps, blockers)` — it releases
  your claim and writes the handoff for the next agent.

### Shared state and coordination

- Write cross-agent working state with `blackboard_write(key, value,
  scope: "PROJECT-ID")`; use scope `global` only for genuinely
  board-wide facts.
- Guard shared or exclusive resources with
  `advisory_lock_acquire(name, ttlSeconds)`; always
  `advisory_lock_release(name)` when done. Locks are advisory and
  TTL-expiring — respect others' holdings rather than forcing past them.
- If you learn something that another agent needs soon, use
  `message_send(to, body)`; only `message_broadcast` when everyone needs
  it.

### Resume / handoff hygiene

- On session end or natural checkpoint: write the current delta onto the
  board (`blackboard_write` under `PROJECT-ID`), hand off any claimed
  tasks, then promote durable outcomes to long-term memory with
  `promote_to_memory(entity, facts)`.
- When picking up someone else's work, read the task's handoff history
  and the board before asking the human.

### Auditing

- Every mutation is attributed to your agent identity and logged. Read
  the trail with `coordination_event_log()`; the whole live picture is
  `board_status()`.
```

---

## Why each rule exists

- **context_pack first** — raw scans waste tokens on cold state; the pack
  is pre-ranked and budget-limited.
- **Atomic claims** — CouchDB MVCC writes mean a raced claim fails clean;
  agent code doesn't need its own leases.
- **Handoff over abandon** — Continuum has no "silent worker died" signal
  other than stale claims; `task_handoff` is the structured answer.
- **Advisory locks** — they cannot physically block anyone; the protocol
  relies on agents respecting holdings. State this in rules so models
  don't treat locks as kernel mutexes.
- **promote_to_memory** — keeps long-term memory (Heliofane) free of
  agent churn and step noise; only outcomes cross over.
