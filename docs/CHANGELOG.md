# Changelog

All notable behavior changes ship in this file alongside the code that
introduces them (see AGENTS.md). Version parity is enforced between
`APPLICATION_VERSION`, the FreeBSD port's `DISTVERSION`, and the git tag.

## Unreleased

### Added

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
