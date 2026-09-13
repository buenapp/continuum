# Connecting Agents to Continuum

Continuum is a hosted MCP server. Every agent connects with its own API
key; the key name (e.g. `devin`) becomes its identity for task claims,
locks, presence, and the audit log. Configure each client once with the
server URL and that agent's key.

## What to collect first

| Item | Where it comes from |
|---|---|
| Endpoint URL | `https://<your-front-stack-host>/mcp` (TLS terminates at the estate front stack) |
| Agent name + key | The `[agents]` map in the server's `settings.ini` |

Non-TLS direct URL is `http://<continuum-host>/mcp` and acceptable on a
trusted segment between the front stack and the app; agent-facing clients
should use the TLS front door.

## Client configuration

All variants below send the key as the `X-Api-Key` header.
`Authorization: Bearer <key>` is interchangeable — use whichever the
client makes easier.

### Zed

`settings.json` (user level) — Zed speaks Streamable HTTP natively:

```json
{
  "context_servers": {
    "continuum": {
      "url": "https://continuum.example.com/mcp",
      "headers": { "X-Api-Key": "paste-agent-key-here" }
    }
  }
}
```

### Claude Code

```sh
claude mcp add --transport http continuum \
  https://continuum.example.com/mcp \
  --header "X-Api-Key: paste-agent-key-here"
```

(or the equivalent `mcpServers` entry in `~/.claude.json` with a
`headers` map on a `"type": "http"` server).

### Devin (desktop CLI / web sessions)

`~/.config/devin/mcp_config.json`:

```json
{
  "mcpServers": {
    "continuum": {
      "serverUrl": "https://continuum.example.com/mcp",
      "headers": { "X-Api-Key": "paste-agent-key-here" }
    }
  }
}
```

### Windsurf (Cascade)

`~/.codeium/windsurf/mcp_config.json`:

```json
{
  "mcpServers": {
    "continuum": {
      "serverUrl": "https://continuum.example.com/mcp",
      "headers": { "X-Api-Key": "paste-agent-key-here" }
    }
  }
}
```

### MCP Inspector / local debugging

Run the stdio transport against a checked-out repo (identity is the
fixed `stdio` agent):

```sh
npx @modelcontextprotocol/inspector php bin/mcp-stdio
```

## Session protocol (what agents should do)

Continuum is the coordination surface, not a chat log. Per session
(the same protocol is also exposed as selectable MCP prompt templates:
`session_bootstrap`, `claim_and_serve`, `handoff`, `milestone_sync` —
with argument autocompletion for scopes, task ids, and board keys):

1. **Register + heartbeat**: `agent_register` once (capabilities,
   label), then `agent_heartbeat(working_on=...)` whenever focus changes.
2. **Pull context before acting**: `context_pack(scope, task_id?)` gives
   the budget-curated brief — open tasks, recent board entries, agent
   presence. Prefer it over raw scans. Add a `query` term for relevance
   ranking (semantic when the operator configured `[embeddings]`).
   Resource-capable clients can attach the same brief directly as
   `continuum://context/pack/{scope}` instead of calling the tool.
3. **Claim before working**: `task_claim` is atomic (MVCC); a raced
   claim fails with a conflict error. Work transitions via
   `task_update_status(...)`.
4. **Advisory locks for shared resources**: `advisory_lock_acquire` has
   a TTL and is cooperation-based — holdings don't hard-block anyone.
   Always check `advisory_lock_check` first, and release on completion.
   Stealing another agent's task claim (`task_claim` on a held task) or
   force-releasing a foreign lock requires explicit confirmation:
   modern clients get an interactive prompt; everyone else passes the
   `confirm` argument (`{"action": "accept", "content": {"approve": true}}`).
5. **Shared state on the board**: `blackboard_write/read/keys/delete`
   with scopes (`global` by default; an agent-named scope is owned by
   that agent for deletes).
6. **Inboxes**: `message_inbox_pull` at session start and between long
   turns; `message_send` / `message_broadcast` for coordination.
7. **Handoff, don't abandon**: `task_handoff(task_id, summary, ...)`
   writes the structured handoff document, releases your claim, and
   re-queues the task.
8. **Promote durable outcomes**: when a handoff or completion produced
   something permanently true, call `promote_to_memory` (writes to
   Heliofane). Continuum owns what happens next; Heliofane owns what is
   true.

Every mutation is attributed to your API key's agent name and written
to the append-only event log (`coordination_event_log` to inspect).

### Subscriptions (modern-protocol clients)

Instead of polling reads, a client speaking protocol revision
2026-07-28 can open `subscriptions/listen` with
`{"notifications": {"resourceSubscriptions": ["continuum://tasks", "continuum://locks"]}}`
and receive `notifications/resources/updated` as the board changes
(exact-URI matching; subscribe to the static list URIs and/or specific
`{id}`/`{key}` URIs). Resource reads are then plain `resources/read`
calls. Only `resourceSubscriptions` is honored; list-changed
notifications are not emitted (the tool/resource surface is static).
The stdio transport does not multiplex subscriptions — use the HTTP
endpoint for them.

## Operator checklist

1. Front-stack route: `<public-name>` → app `:8089` with PROXY protocol
   v2 enabled (TLS terminates at the front stack; the app never does TLS
   itself).
2. Add the agent name/key line to `[agents]` in
   `/usr/local/www/continuum/config/settings.ini` (no restart needed —
   config is read per request).
3. Smoke test from the agent host:
   ```sh
   curl -H "X-Api-Key: <key>" https://continuum.example.com/health
   ```
4. For observability: `/metrics` (Prometheus, same key auth) and the
   dashboards page `GET /` (HTML, same key auth).
