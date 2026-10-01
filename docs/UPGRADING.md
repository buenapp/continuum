# Upgrading Continuum

Continuum follows semver. `UPGRADING.md` lists everything an operator must
do when moving between versions; it ships with the code that requires it.

## 0.3.0 (messaging targeting + lease/ack)

No operator action: no new settings keys, no engine schema changes, and
existing inboxes need no migration (messages without an id or targeting
fields are simply untargeted, as before).

Client-visible changes:

- `message_send` accepts optional `session`, `task`, `kind`,
  `priority`, `expires`, `replyTo`, `epoch` (control-kind only); its
  result gains the message `id`.
  A `task` target resolves to the session recorded on the task by its
  claim and fails when the task is unknown or unbound.
- New tools `message_lease` (non-destructive, session-scoped read) and
  `message_ack` (delete by id). Session-scoped readers should declare
  `params._meta.session` on their MCP requests; it also makes
  `task_claim` bind the task to that session. Without a declared
  session, the transport session id (`MCP-Session-Id`) is used.
- `message_inbox_pull` is unchanged for untargeted messages, but it no
  longer returns session-addressed or currently leased messages at all;
  those require `message_lease`. This is the fix for cross-session
  misdelivery under a shared agent identity.
- Task summaries (all `task_*` outputs and `task_list`) gain a
  `session` field, null when the task is unbound. Consumers that skip
  unknown fields are unaffected.

## 0.2.5

No operator action. Tool descriptions only; no protocol, setting, or schema
changes.

## 0.2.4

No operator action. The packaged server instructions now steer agent-level
presentation of internal task ids; clients receive it in the `initialize`
handshake automatically.

## 0.2.3

Additive only: `agent_heartbeat`/`agent_register` results gain a
`session` field (null for session-less clients), and directory outputs
(`board_status`, `continuum://agents[/{id}]`) gain a `sessions` array
per agent. Consumers that skip unknown fields are unaffected. Presence
semantics change for clients sending `MCP-Session-Id`: working-on notes
are per-session instead of last-writer-wins per identity.

## 0.2.2

Same migration as 0.2.1, extended to the remaining thirteen tools:
mutations (`task_create`, `task_claim`, `task_update_status`,
`task_handoff`, `blackboard_write`, `blackboard_delete`,
`advisory_lock_acquire`, `advisory_lock_release`, `agent_register`,
`agent_heartbeat`, `message_send`, `message_inbox_pull`,
`message_broadcast`, `promote_to_memory`) now also return dual format —
their `result.content[0].text` is a human-readable sentence, and the
machine payload is `result.structuredContent`. Clients that were
JSON-parsing the text block must switch to `structuredContent`; the
packaged `bin/smoke-test` already does.

## 0.2.1

No operator action: no settings keys, no engine schema changes, no
deploy differences beyond `pkg upgrade`-equivalents once tagged.
Clients/scripts that parse read-tool output out of
`result.content[0].text` as JSON must switch to
`result.structuredContent` — for the eight read tools the text block is
now Markdown. Tools without a schema (mutations) still return
JSON-encoded text exactly as before.

## 0.2.0

`pkg upgrade` is sufficient: no settings keys, no engine schema changes;
the tool surface is unchanged (see the compatibility notes below for
behavior details). Everything added is capability-advertised and
optional.

## 0.1.0

First tagged release: `v0.1.0` == port `DISTVERSION` == `APPLICATION_VERSION`.
Package: `php84-continuum` from the estate pkg repo. Fresh installs:

1. `cp /usr/local/www/continuum/config/settings.ini.sample settings.ini`
   and fill in `[agents]` keys + engine endpoints.
2. Create the CouchDB databases `continuum_plans`, `continuum_tasks`,
   `continuum_events`, `continuum_boards` (prefix configurable).
3. Create the ArcadeDB database named in `[arcadedb] database`
   (default `continuum`); the graph schema (Task/Agent vertices,
   DEPENDS_ON/CLAIMED_BY edges) is ensured automatically at startup.
4. Point the Apache vhost at `/usr/local/www/continuum/public/` (see the
   packaged `continuum.conf.sample` example) or run
   `/usr/local/www/continuum/bin/mcp-stdio`.
5. Verify read-only: `/health` probe and a `server_info` tools/call.

## Compatibility notes

- **MRTR elicitation (0.2.0)**: `task_claim` and
  `advisory_lock_release` gained an optional `confirm` argument;
  existing calls without it behave exactly as before, except that
  claiming a task another agent holds now yields a confirmation
  challenge (2026-07-28 clients with the elicitation capability:
  interactive prompt; everyone else: a tool-level error explaining the
  `confirm` escape hatch) instead of the old "not claimable" failure.
  Owner-only releases and the blackboard delete restriction are
  unchanged.


- **MCP resources, prompts, completion (0.2.0)**: purely additive.
  The server now advertises the `resources`, `prompts`, and
  `completions` capabilities and answers the corresponding method
  families. No settings keys, no engine schema changes, and the tool
  surface is unchanged — clients that ignore them are unaffected.
- **Subscriptions (0.2.0)**: `subscriptions/listen` answers only on
  the 2026-07-28 protocol revision; legacy clients see -32601 and are
  unaffected. Each open listen stream holds one PHP worker for its
  lifetime — size the pool if many agents will keep subscriptions open
  (agents that only read don't need them).


- **CLAIMED_BY edges** carry a `task` property (added during Phase 4).
  Edges created without it will not be removed by `task_update_status`
  done/cancelled or `task_handoff`; delete them by rid:
  `SELECT FROM CLAIMED_BY` then `DELETE FROM CLAIMED_BY WHERE @rid = <rid>`.
