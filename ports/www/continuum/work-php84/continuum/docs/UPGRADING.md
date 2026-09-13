# Upgrading Continuum

Continuum follows semver. `UPGRADING.md` lists everything an operator must
do when moving between versions; it ships with the code that requires it.

## Current: no stable release yet

The first tagged release has not been cut. Deployments track `master`
through the estate package repo. Before the first tag, fresh installs
need:

1. Copy `src/config/settings.ini.sample` to `src/config/settings.ini` and
   fill in engine endpoints and `[agents]` keys.
2. Create the CouchDB databases: `continuum_plans`, `continuum_tasks`,
   `continuum_events`, `continuum_boards` (prefix configurable).
3. Create the ArcadeDB database named in `[arcadedb] database`
   (default `continuum`); the graph schema (Task/Agent vertices,
   DEPENDS_ON/CLAIMED_BY edges) is ensured automatically at startup.
4. Point an HTTP vhost at `src/public/` (see
   `etc/apache24/continuum.conf.sample`) or run `src/bin/mcp-stdio`.

## Compatibility notes

- **CLAIMED_BY edges** carry a `task` property (added during Phase 4).
  Edges created without it will not be removed by `task_update_status`
  done/cancelled or `task_handoff`; delete them by rid:
  `SELECT FROM CLAIMED_BY` then `DELETE FROM CLAIMED_BY WHERE @rid = <rid>`.
