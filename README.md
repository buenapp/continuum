# Continuum

Hosted blackboard coordination MCP server for multi-agent orchestration:
task routing, claims, advisory locks, handoffs, agent inboxes, and an
append-only event log.

Continuum stores **what happens next**. For long-term knowledge ("what is
true"), use Heliofane. Durable outcomes from completed work are promoted to
Heliofane at handoff time.

## MCP surface

All calls are attributed to a per-agent API key (`X-Api-Key` or
`Authorization: Bearer`) and every mutation is written to the audit event
log.

| Phase | Tools |
|-------|-------|
| Core | `server_info` |
| 1 | `blackboard_write` `blackboard_read` `blackboard_keys` `blackboard_delete` `context_pack`; `task_create` `task_list` `task_claim` `task_update_status` `task_handoff`; `advisory_lock_acquire` `advisory_lock_release` `advisory_lock_check` |
| 2 | `agent_register` `agent_heartbeat`; `message_send` `message_inbox_pull` `message_broadcast`; `coordination_event_log` `board_status`; HTTP dashboard |
| 3 | milestone-sync adapter + `promote_to_memory`; semantic ranking via embeddings |

Beyond tools, the modern (2026-07-28) MCP surface:

| Capability | What you get |
|---|---|
| Resources | URI-addressable reads: `continuum://board/index`, `continuum://board/{scope}/{key}`, `continuum://snapshot/{scope}`, `continuum://tasks` and `continuum://tasks/{id}`, `continuum://agents[/{id}]`, `continuum://locks[/{name}]`, `continuum://events[/since/{ts}]`, `continuum://context/pack/{scope}` (markdown brief). Per-read `lastModified` annotations; live data, no caching |
| Prompts | `session_bootstrap`, `claim_and_serve`, `handoff`, `milestone_sync` — the coordination contract as selectable templates |
| Completion | `completion/complete` fills prompt arguments and resource placeholders with live scopes, task ids, board keys, agent ids, lock names |
| Subscriptions | `subscriptions/listen` streams `notifications/resources/updated` as mutations land (change fan-out over ValKey pub/sub) |
| Elicitation (MRTR) | Confirmation prompts on cross-owner steals (`task_claim`, `advisory_lock_release`); a `confirm` argument is the deterministic answer path for clients without elicitation |

Handshake-era clients (2025-11-25 and older) keep full access to tools,
resources, and prompts; subscriptions and MRTR elicitation degrade to
documented error paths.

### Structured output (dual format)

The eight read tools — `server_info`, `blackboard_read`,
`blackboard_keys`, `task_list`, `advisory_lock_check`, `context_pack`,
`board_status`, `coordination_event_log` — return results in the MCP
(2025-06-18) dual format: a human-first Markdown `content` text block
plus `structuredContent` carrying the same facts as JSON, validated
against the `outputSchema` each tool advertises. Task renderings lead
with the title (and the linked Phorge id when one is set); the internal
coordination id trails in parentheses. Mutation tools keep plain
JSON-as-text results for now.

Tool naming: explicit snake_case nouns, spelled out (no `bb_`, `msg_`,
bare `lock_`). Qualify when the plain word lies about the contract
(e.g. `advisory_lock_` for cooperation-based TTL locks).

## Terminology

Two different "task" concepts exist in this space; keep them separate in
code, docs, and conversation:

- **Board task** — Continuum's work item (`task_create`,
  `task_claim`, `continuum://tasks/{id}`): the durable coordination unit
  with scopes, claims, handoffs, and a milestone trail.
- **MCP task call** — the MCP specification's `tasks` extension for
  long-running *tool invocations* (poll-based `tasks/get`). Continuum
  does not implement that extension; everything Continuum calls a task
  is a board task.

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

## Install

Requirements: **PHP 8.4** (extensions: ctype, curl, filter, mbstring),
**ValKey**, **CouchDB 3.x**, and **ArcadeDB**. Target OS is FreeBSD; the
app is portable PHP and runs anywhere the engines are reachable.

### FreeBSD package

The port in `ports/www/continuum` builds `php84-continuum`, installed
under `/usr/local/www/continuum`:

```sh
pkg install php84-continuum
```

### From source (development)

```sh
git clone https://pacyworld.dev/buenapp/continuum.git
cd continuum
cp src/config/settings.ini.sample src/config/settings.ini
```

Fill in `[agents]` (one long-random key per agent) and the engine
endpoints in `settings.ini`, then provision the durable engines:

- **CouchDB**: create `continuum_plans`, `continuum_tasks`,
  `continuum_events`, `continuum_boards` (the prefix is
  `[couchdb] database_prefix`).
- **ArcadeDB**: create the database named in `[arcadedb] database`
  (default `continuum`). The graph schema (Task/Agent vertices,
  DEPENDS_ON/CLAIMED_BY edges) is ensured automatically at startup.

Serve `src/public/` with Apache (sample vhost with PROXY protocol v2 and
a port-80 health vhost: `etc/apache24/continuum.conf.sample`; Apache runs
plain HTTP — TLS terminates on the front stack), or debug over stdio:

```sh
npx @modelcontextprotocol/inspector php src/bin/mcp-stdio
```

Verify the install:

```sh
curl -H "X-Api-Key: <agent-key>" https://<host>/health
curl -H "X-Api-Key: <agent-key>" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"server_info","arguments":{}}}' \
  https://<host>/mcp
```

Client configuration and the per-session coordination protocol:
`docs/AGENT-SETUP.md`.

## Deploy / connect

Packaged install and vhost setup: see `docs/UPGRADING.md` and
`etc/apache24/continuum.conf.sample`. Agent client configuration and the
per-session coordination protocol: `docs/AGENT-SETUP.md`.

## Development

```sh
phpunit                                   # unit tests
php src/bin/mcp-stdio                     # manual MCP debugging
php src/public/index.php                  # served by a web server in practice
```

Configuration: copy `src/config/settings.ini.sample` to
`src/config/settings.ini` and fill in engine endpoints and agent keys.
