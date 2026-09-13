# Project Rules — Continuum

## Public Repository

This repo is PUBLIC (pacyworld.dev/buenapp/continuum). Never commit:
internal hostnames/IPs, credentials, tokens, `.multipass` contents, estate
paths, or infrastructure details. Engine endpoints in `settings.ini.sample`
stay commented out with localhost-shaped defaults.

## Storage Engines

- Ephemeral: **ValKey** (never say Redis in user-facing text)
- Durable: CouchDB — MVCC docs, append-only event log
- Structural: ArcadeDB — dependency/relationship graph
- All engine access goes through the `ContinuumStorage` abstraction
  (`src/classes/Continuum/Storage/`). Tools never call engines directly.

## Verification Steps (before every commit)

1. `phpunit` — all green. No exceptions.
2. If `src/classes/` or `src/public` changed: `php -l` the touched files
   (phpunit must catch runtime breakage; unit suites may not load
   public/bin entry points).

### Version Parity (release time)

These three must match on every release tag:
- `APPLICATION_VERSION` in `src/system/app.conf.php`
- `DISTVERSION` in `ports/www/continuum/Makefile`
- The git tag (`v<version>`)

### Settings Parity

Any new `$SETTINGS->getString()/getInt()/getBool()` key must have a
corresponding commented entry in `src/config/settings.ini.sample`.

### Documentation Changes Ship With Code

Update `docs/CHANGELOG.md` and `docs/UPGRADING.md` alongside behavior
changes. Documentation is not a follow-up task.

## Tool Naming

MCP tool names are model-facing: use explicit snake_case nouns, fully
spelled out (`blackboard_write`, not `bb_write`; `message_send`, not
`msg_send`). Qualify a name when the plain word misstates the contract
(`advisory_lock_acquire`, because holders cannot hard-block others).
Renames keep the old name in the attribute's `renamedFrom` metadata.

## Serving Topology

Apache NEVER terminates TLS for Continuum. TLS ends at the estate front
stack (Hitch -> Varnish -> HAProxy/ATS). Apache runs a second vhost on an
alternate port accepting PROXY protocol v2 (`mod_remoteip`,
`RemoteIPProxyProtocol On`) plus a plain local :80 vhost for health checks.
Sample: `etc/apache24/continuum.conf.sample`.

## Deployment

Production deploys happen ONLY via the estate poudriere-built pkg repo.
Never scp files or `pkg install /tmp/*.pkg` to the Continuum node.
