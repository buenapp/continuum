# Changelog

All notable behavior changes ship in this file alongside the code that
introduces them (see AGENTS.md). Version parity is enforced between
`APPLICATION_VERSION`, the FreeBSD port's `DISTVERSION`, and the git tag.

## Unreleased

### Added

- XMPP inbound hook (issue #3, first slice): `POST /mcp/xmpp-inbound`
  accepts one message stanza as JSON from the bridge sidecar and files
  it into the target agent's inbox through the normal envelope + audit
  path (`message_xmpp_inbound` event type). Only the agent named by
  `[xmpp] bridge_agent` may call it; without that setting the hook
  rejects every call, so the transport stays strictly optional.

## 0.3.0 (2026-10-01)

### Added

- Design proposal for the XMPP transport
  ([issue #3](https://pacyworld.dev/buenapp/continuum/issues/3)):
  `docs/XMPP-TRANSPORT.md` recommends a Zig sidecar on xmppd's
  `lib/xmppc` client core over an in-process PHP extension, and pins
  down the IPC/API shape plus the verified xmppd capability answers.

- Session/task-addressed messaging (implements
  [issue #2](https://pacyworld.dev/buenapp/continuum/issues/2)):
  - `message_send` gains optional `session`, `task`, `kind`
    (`notice|context|directive|control`), `priority` (`normal|urgent`),
    `expires` (ISO 8601), `replyTo`, and `epoch` (master epoch; allowed
    on `control` messages only) fields. Every message now
    carries an `M-XXXXXXXX` id. A `task` target resolves to the session
    bound to that task; sending to an unknown or unbound task fails
    rather than misdelivering.
  - New tools `message_lease` and `message_ack`: reads lease messages
    (the caller's session plus untargeted ones) instead of deleting
    them; an unacknowledged lease expires and returns the messages;
    `message_ack` deletes by id so redelivery is idempotent for the
    reader.
  - Clients declare their logical session as `params._meta.session`;
    it wins over the transport session id for inbox scoping and task
    binding. `task_claim` records that session on the task card
    (surfaced in task summaries as `session`); handoffs and terminal
    or pending statuses release the binding.
  - Sends, leases and acks are written to the event log
    (`message_lease` / `message_ack` event types).

### Changed

- `message_inbox_pull` keeps its destructive-read behavior but only
  ever touches untargeted, unleased, unexpired messages; anything
  addressed to a session is exclusively reachable through a
  session-scoped lease. Expired messages are dropped lazily by any
  inbox operation. Inbox reads/writes are optimistic-concurrency
  guarded (WATCH/MULTI/EXEC) instead of a bare LRANGE+LTRIM.

## 0.2.5 (2026-09-27)

### Changed

- Tool descriptions are tightened and now state the defaults that the
  generated input schemas do not carry (`tokenBudget`, `limit`,
  `ttlSeconds`, `priority`, `createIfMissing`). tools/list drops from
  1,691 to 1,551 tokens (Qwen tokenizer). Tool names, parameters and
  behavior are unchanged.

## 0.2.4 (2026-09-14)

### Changed

- Server instructions now steer human-facing presentation: task tables
  and summaries should lead with title/scope/status (and the linked
  Phorge id), with internal `T-XXXXXXXX` coordination ids included only
  when asked or when another agent will act on them. The instruction
  ships in the MCP `initialize` handshake, so every connected model
  receives it regardless of which tools it calls.

## 0.2.3 (2026-09-13)

### Changed

- Presence is session-scoped: one API key can back several concurrent
  agent sessions, and they no longer clobber each other's working-on
  notes. The transport session id (HTTP `MCP-Session-Id`; stdio
  synthesizes `stdio-<pid>`) keys a per-session presence record with a
  4h TTL refreshed on every beat. `agent_heartbeat`/`agent_register`
  gain a `session` field; stale session cards expire and prune
  themselves; agent freshness follows the newest session beat.
  Directory reads (`board_status`, `continuum://agents*`,
  `context_pack`) list sessions under their agent. Clients without a
  session id keep the legacy agent-level behavior (last writer wins).

## 0.2.2 (2026-09-13)

### Changed

- All tools now answer in the MCP 2025-06-18 dual format: the thirteen
  mutation-side tools (`task_create`, `task_claim`, `task_update_status`,
  `task_handoff`, `blackboard_write`, `blackboard_delete`,
  `advisory_lock_acquire`, `advisory_lock_release`, `agent_register`,
  `agent_heartbeat`, `message_send`, `message_inbox_pull`,
  `message_broadcast`, `promote_to_memory`) return a human-readable
  outcome sentence as the text block plus matching `structuredContent`,
  each with an advertised `outputSchema`. Structured payloads are
  byte-for-byte what these tools returned before; `bin/smoke-test` now
  consumes `structuredContent` for them.

## 0.2.1 (2026-09-13)

### Changed

- Read tools now answer in the MCP 2025-06-18 dual format: a human-first
  Markdown `content` text block plus matching `structuredContent`, with
  each read tool (`server_info`, `blackboard_read`, `blackboard_keys`,
  `task_list`, `advisory_lock_check`, `context_pack`, `board_status`,
  `coordination_event_log`) advertising an `outputSchema` on
  `tools/list`. `server_info` also gained the `readOnlyHint` annotation
  it always qualified for.
- Task presentation is human-first: `task_list`, `context_pack`, and
  `board_status` render task lines with the title (and linked Phorge
  task id) leading and the internal coordination id trailing
  (`- Fix parser [in_progress, p1, @zed] (T-68C9770B)`). Task summaries
  in tool output now include `phorge_task_id`. ID generation itself is
  unchanged.
- `promote_to_memory` is now registered only when `[heliofane]` is
  configured (both entry points) — capability discovery instead of a
  runtime "not configured" error. The `handoff` and `milestone_sync`
  prompt templates mention memory promotion only when the bridge exists,
  so unconfigured deployments never steer agents at a tool that isn't
  there.

## 0.2.0 (2026-09-13)

### Added

- MRTR elicitation for cross-owner operations (MCP 2026-07-28):
  - `task_claim` can now steal a task held by another agent
    (claimed/in_progress/blocked): the first call answers
    `input_required` with an elicitation form ("steal the claim?") on
    capability-capable modern clients; approving transfers ownership,
    clears the prior CLAIMED_BY edge, and logs a `task_steal` event
    with both parties. Declining keeps the task where it is.
  - `advisory_lock_release` can force-release a foreign lock the same
    way (expired/orphaned locks are the intended target); approvals log
    `advisory_lock_force_release` with the prior owner. Compare-and-delete
    semantics for owner releases are unchanged.
  - Both tools accept a `confirm` argument so non-MRTR clients (and
    scripts) answer deterministically without the round trip.
  - Blackboard deletes deliberately did NOT gain an override: entry
    deletion is an authorization rule (author/scope owner), not an
    advisory courtesy.
- MCP prompts + completion: the coordination contract is now
  discoverable as prompt templates (`session_bootstrap`,
  `claim_and_serve`, `handoff`, `milestone_sync`) via `prompts/list`
  and `prompts/get`; the bootstrap prompt links the context-pack
  resource for the requested scope. `completion/complete` suggests live
  values — scopes, task ids (open first), board keys (scope-aware via
  completion context), agent ids, lock names — for both prompt
  arguments and resource template placeholders. Unknown prompts/refs
  answer -32602; capabilities (`prompts`, `completions`) advertised on
  both entry points.
- Subscribe-and-Notify (MCP 2026-07-28): `subscriptions/listen` on the
  `/mcp` endpoint opens a long-lived SSE stream of
  `notifications/resources/updated` for the subscribed resource URIs —
  `continuum://tasks`, `continuum://tasks/{id}`, `continuum://board/...`,
  `continuum://locks`, `continuum://events`, `continuum://agents/...`.
  Mutations publish changed URIs through ValKey pub/sub (cross-worker
  fan-out); the supported set is `resourceSubscriptions` only.
  Bare heartbeat ticks deliberately do not notify; meta changes
  (register, working-on, capabilities, label) do. stdio acknowledges and
  gracefully closes listen requests (no multiplexing without a Comal
  loop). Legacy-era clients get -32601, matching their capability map.
- MCP resources: the board's read surface is now URI-addressable
  (`resources/list`, `resources/templates/list`, `resources/read`; the
  `resources` capability is advertised automatically). Tools remain the
  mutation side; nothing about the tool surface changed.
  - Statics: `continuum://board/index` (all scopes + keys with
    attribution), `continuum://tasks` (non-terminal tasks), `continuum://agents`
    (presence directory), `continuum://locks` (held advisory locks),
    `continuum://events` (50-event tail).
  - Templates: `continuum://board/{scope}/{key}`, `continuum://snapshot/{scope}`,
    `continuum://tasks/{id}` (full card incl. notes, handoffs, graph
    neighborhood), `continuum://agents/{id}`, `continuum://locks/{name}`,
    `continuum://events/since/{timestamp}`, and
    `continuum://context/pack/{scope}` — the session-start brief as a
    `text/markdown` resource clients can attach directly.
  - Per-read `lastModified` annotations come from the underlying doc
    timestamps (board entries, tasks, events) and heartbeat times
    (agents); `audience: assistant` on everything, `priority: 0.9` on the
    context pack. Read results carry `ttlMs: 0` on modern-protocol
    requests — the board is live data.
  - Registered on both entry points: `/mcp` (Streamable HTTP) and
    `bin/mcp-stdio`.
- Release engineering: BSD-3-Clause LICENSE, `ports/www/continuum`
  FreeBSD port (php84-continuum, installs to `/usr/local/www/continuum`,
  Apache front-stack vhost example under EXAMPLES).
- Metrics + CI (Phase 2 remainder):
  - `/metrics` (authenticated, Prometheus text format): live gauges
    `continuum_tasks_open`, `continuum_agents_registered`,
    `continuum_locks_held`, `continuum_info`; persisted counters
    (`continuum_tool_calls_total{tool=...}`, `continuum_mcp_requests_total`)
    and `continuum_http_request_duration` histogram in ValKey.
  - Per-tool counter + request duration instrumented at the transport
    edge, flushed after the request; metrics failures never propagate.
  - Forgejo Actions workflow runs `phpunit` on push.
- Phase 6 (bridges):
  - Milestone sync adapter seam (`Continuum\Bridge\MilestoneSyncAdapterInterface`)
    + `NullMilestoneSyncAdapter`. `task_claim` fires `started`;
    `task_update_status` fires `blocked`/`resolved` with reason/summary and
    `phorge_task_id`. No tracker implementation ships — the interface is
    the anti-corruption seam for a future Phorge/Conduit adapter;
    `[milestones] adapter` accepts only `none`.
  - `promote_to_memory` — distilled facts promoted to long-term memory
    (Heliofane) at handoff/completion via `HeliofaneMcpBridge`
    (initialize/SSE handshake, stateless-OK, `note` then `remember`
    fallback; Heliofane's textual `**ERROR**: ...` responses count as
    failures). Needs `[heliofane]` config; errors cleanly when unset.
    Write path live-verified against a local QA Heliofane instance.
  - `context_pack` gains `query` param + ranking seam: `LexicalRanker`
    default; `EmbeddingRanker` when `[embeddings] url` is set
    (OpenAI-compatible endpoint, cosine re-rank of tasks/board entries).
    Ranking failures degrade to default ordering, never break the pack.
- Phase 5 tool wave (liveness, messaging, visibility):
  - `agent_register` / `agent_heartbeat` — presence records with
    capabilities, label, and `working_on`; heartbeats are churn and are
    deliberately not logged.
  - `message_send` / `message_inbox_pull` / `message_broadcast` —
    per-agent inboxes with a destructive-read pull model; broadcast
    skips the sender and logs the recipient count.
  - `coordination_event_log` — read the append-only audit trail, newest first, with
    `type` / `scope` / `since` filters and a limit.
  - `board_status` — whole-board snapshot: agents with last-seen, open
    tasks with owners, held locks (enumerate via ValKey SCAN), board
    scopes, queue depths, recent events.
  - Read-only HTML dashboard at `GET /` (authenticated with any agent
    key; server-rendered, auto-refreshes).
- Phase 4 tool wave (blackboard core):
  - `blackboard_write` / `blackboard_read` / `blackboard_keys` /
    `blackboard_delete` — scoped board entries
    with per-entry attribution; deletes restricted to the author or the
    agent owning the scope; scope/key segments are validated (CouchDB
    reserves `_`-prefixed ids, `/` delimits scope from key).
  - `task_create` / `task_list` / `task_claim` / `task_update_status` /
    `task_handoff` — state machine `pending → claimed → in_progress →
    (blocked|review) → done/cancelled`; claims are MVCC-guarded so a raced
    claim fails with a conflict error instead of double-claiming; status
    changes and handoffs are owner-only; `done`/`cancelled` release the
    claim edge in the dependency graph; handoffs re-queue the task.
  - `advisory_lock_acquire` / `advisory_lock_release` / `advisory_lock_check` — advisory TTL locks;
    compare-and-delete release means a non-owner cannot steal a lock.
  - `context_pack` — the ranked, token-budgeted session-start brief
    (focused task with dependency graph neighborhood, open tasks, board
    entries, agent presence). Lexical ranking for now; embedding-based
    ranking slots in behind the same seam later.
- Every mutation is written to the append-only event log
  (`continuum_events`) with the calling `CONTINUUM_AGENT` identity.
- Tool surface is registered in both the HTTP entry point and the stdio
  transport (`bin/mcp-stdio`, fixed `stdio` identity).

### QA environment

- `bin/smoke-test`: end-to-end wire test driving a live server —
  health, initialize/discover, catalogs, task lifecycle, board
  round-trip via tools and resources, subscription stream with a forked
  listener, and the MRTR steal cycle (needs `--key2`). Ships in the
  port and runs against any URL + agent key. Companion doc: docs/QA.md
  (QA-environment setup, isolation rules, release QA checklist).

### Documentation

- README now covers the full MCP surface (resources, prompts,
  completion, subscriptions, MRTR elicitation), adds a Terminology
  section disambiguating Continuum board tasks from the MCP `tasks`
  extension (long-running tool calls — not implemented), and gains
  installation instructions (port package, source checkout, engine
  provisioning, verification). `pkg-descr` refreshed to match.

### Fixed (during live verification)

- ArcadeDB has no `DELETE EDGE` statement and no link traversal in `WHERE`;
  claim edges now carry a `task` property and removal matches on it.
- Task ids are random (`T-XXXXXXXX`) with retry-on-collision; CouchDB
  `_uuids` is sequential per node, so prefix-truncated uuids collided.

## 0.1.0 (unreleased baseline)

- Initial scaffold: Enchilada app skeleton, `/mcp` Streamable HTTP
  endpoint, per-agent API-key auth, `bin/mcp-stdio`, `server_info`.
- Storage layer: `Continuum\Storage\ContinuumStorage` facade over ValKey
  (ephemeral), CouchDB (durable), ArcadeDB (structural).
- Apache vhost sample for the estate front-stack serving model
  (`etc/apache24/continuum.conf.sample`).
