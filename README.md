# Continuum

Hosted blackboard coordination MCP server for multi-agent orchestration:
task routing, claims, advisory locks, handoffs, agent inboxes, and an
append-only event log.

Continuum stores **what happens next**. For long-term knowledge ("what is
true"), use Heliofane. Durable outcomes from completed work are promoted to
Heliofane at handoff time.

## Tools

All calls are attributed to a per-agent API key (`X-Api-Key` or
`Authorization: Bearer`) and every mutation is written to the audit event
log.

| Phase | Tools |
|-------|-------|
| Core | `server_info` |
| 1 | `blackboard_write` `blackboard_read` `blackboard_keys` `blackboard_delete` `context_pack`; `task_create` `task_list` `task_claim` `task_update_status` `task_handoff`; `advisory_lock_acquire` `advisory_lock_release` `advisory_lock_check` |
| 2 | `agent_register` `agent_heartbeat`; `message_send` `message_inbox_pull` `message_broadcast`; `coordination_event_log` `board_status`; HTTP dashboard |
| 3 | milestone-sync adapter + `promote_to_memory`; semantic ranking via embeddings |

Tool naming: explicit snake_case nouns, spelled out (no `bb_`, `msg_`,
bare `lock_`). Qualify when the plain word lies about the contract
(e.g. `advisory_lock_` for cooperation-based TTL locks).

## Architecture

Three storage engines behind a `ContinuumStorage` abstraction:

| Layer | Engine | Purpose |
|-------|--------|---------|
| Ephemeral | ValKey | queues, locks, signals, presence |
| Durable | CouchDB | plans, snapshots, event log, board history |
| Structural | ArcadeDB | task dependency graph, agent relationships |

Stack: PHP 8.4, Enchilada Framework, EnchiladaMCP, Tortilla
(Streamable HTTP + SSE at `/mcp`). See `docs/PROTOTYPE.md` for the data
layer design.

## Layout

```
src/
  system/      Enchilada app constants + autoloader (Framework vendored)
  includes/    bootstrap
  config/      settings.ini.sample, instructions.txt
  classes/     Continuum application classes
  public/      index.php (MCP /mcp + /health)
  bin/         mcp-stdio (Inspector debugging)
  libraries/   vendored Enchilada extras (MCP, Tortilla, HTTP, OTLP, Config)
tests/         PHPUnit suite
docs/          design documents
```

## Development

```sh
phpunit                                   # unit tests
php src/bin/mcp-stdio                     # manual MCP debugging
php src/public/index.php                  # served by a web server in practice
```

Configuration: copy `src/config/settings.ini.sample` to
`src/config/settings.ini` and fill in engine endpoints and agent keys.
