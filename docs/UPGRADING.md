# Upgrading Continuum

Continuum follows semver. `UPGRADING.md` lists everything an operator must
do when moving between versions; it ships with the code that requires it.

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

- **MRTR elicitation (unreleased)**: `task_claim` and
  `advisory_lock_release` gained an optional `confirm` argument;
  existing calls without it behave exactly as before, except that
  claiming a task another agent holds now yields a confirmation
  challenge (2026-07-28 clients with the elicitation capability:
  interactive prompt; everyone else: a tool-level error explaining the
  `confirm` escape hatch) instead of the old "not claimable" failure.
  Owner-only releases and the blackboard delete restriction are
  unchanged.


- **MCP resources, prompts, completion (unreleased)**: purely additive.
  The server now advertises the `resources`, `prompts`, and
  `completions` capabilities and answers the corresponding method
  families. No settings keys, no engine schema changes, and the tool
  surface is unchanged — clients that ignore them are unaffected.
- **Subscriptions (unreleased)**: `subscriptions/listen` answers only on
  the 2026-07-28 protocol revision; legacy clients see -32601 and are
  unaffected. Each open listen stream holds one PHP worker for its
  lifetime — size the pool if many agents will keep subscriptions open
  (agents that only read don't need them).


- **CLAIMED_BY edges** carry a `task` property (added during Phase 4).
  Edges created without it will not be removed by `task_update_status`
  done/cancelled or `task_handoff`; delete them by rid:
  `SELECT FROM CLAIMED_BY` then `DELETE FROM CLAIMED_BY WHERE @rid = <rid>`.
