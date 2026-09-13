# Continuum Storage Layer

`Continuum\Storage\ContinuumStorage` is the only class tools may talk to
(`src/classes/Continuum/Storage/`). It composes three engine adapters.

## Engine mapping

| Layer | Engine | Adapter | Holds |
|-------|--------|---------|-------|
| Ephemeral | ValKey | `ValKeyStore` | task queues, advisory TTL locks, signals, agent presence, inboxes |
| Durable | CouchDB | `CouchDBStore` | plans, task docs, board entries, append-only event log |
| Structural | ArcadeDB | `ArcadeDBStore` | Task/Agent vertices, DEPENDS_ON/CLAIMED_BY edges |

## ValKey key schema (prefix `continuum:`)

- `queue:{queue}` LIST — pending task ids
- `task:{id}` HASH — claim hand-off payload snapshot
- `lock:{name}` STR — advisory lock, value=owner, TTL enforced
- `agent:{id}` HASH + `agents` SET — presence/heartbeat
- `inbox:{agentId}` LIST — JSON messages
- `signal:{channel}` — PUBSUB channel (no persistence)

Locks are acquire-by-`SET NX EX` and release by compare-and-delete Lua —
a non-owner can never release someone else's lock.

## CouchDB databases (prefix `continuum_`)

- `plans`, `tasks`, `events`, `boards` — MVCC revisioned on write; a
  stale writer gets a 409, never a silent overwrite.
- `events` is append-only: every mutation logs
  `{agent, type, data, ts}` into it.

## ArcadeDB schema

Vertex types `Task` (unique index on `id`) and `Agent` (unique index on
`name`). `UPSERT` statements require those indexes; `ensureSchema()`
creates types/properties/indexes idempotently. Edges are traversed with
 `out()/in()` label hops (ArcadeDB dialect), not `.inV()`.

## Configuration

Endpoints live in `settings.ini` sections `[valkey]`, `[couchdb]`,
`[arcadedb]` (see `settings.ini.sample`). `ContinuumStorage::fromSettings()`
builds the facade.

## Testing

- Unit suite: `phpunit` (fakes stub RESP/HTTP edges; no live services).
- Live checks run against the local dev jail engines (displaced ports;
  see jail `continuum`). Live verification is manual/integration for now.
