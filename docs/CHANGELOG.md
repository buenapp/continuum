# Changelog

All notable behavior changes ship in this file alongside the code that
introduces them (see AGENTS.md). Version parity is enforced between
`APPLICATION_VERSION`, the FreeBSD port's `DISTVERSION`, and the git tag.

## Unreleased

### Added

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
  - `lock_acquire` / `lock_release` / `lock_check` — advisory TTL locks;
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
