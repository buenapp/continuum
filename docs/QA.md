# QA Environment and Smoke Testing

Unit tests (tests/) mock every engine. The QA flow fills the gap:
Continuum running against real ValKey, CouchDB, and ArcadeDB, exercised
over the real MCP wire by `bin/smoke-test`.

## bin/smoke-test

```sh
php bin/smoke-test --url http://<host>:<port>/mcp --key <agent-key> \
  [--key2 <second-agent-key>] [--timeout 10]
```

Walks: `/health`, `initialize` (capability map), `server/discover`
(modern revision listed), catalog listings (tools/resource templates/
prompts), a task lifecycle (`task_create` → claim), board round-trip via
tools and resources, snapshot + event-log resources, argument completion,
a `prompts/get` render, a live subscription stream (forked listener +
forced write → `notifications/resources/updated`), and — with `--key2` —
the MRTR elicitation cycle (claim steal challenge, approved steal,
declined re-steal, legacy-client fallback). Leaves a `smoke-*` marker
task and board keys behind; run it only against disposable QA installs.
Exit 0 = all non-skipped checks passed.

Requires PHP CLI with `curl` and `pcntl` extensions (pcntl drives the
forked subscription listener).

## QA environment shape

A throwaway target (FreeBSD jail, VM, or spare node) with:

1. ValKey, CouchDB, ArcadeDB running with a **QA-only** database/prefix
   (`continuum_qa_*` on CouchDB, `continuum_qa` on ArcadeDB). If the QA
   target shares a network namespace with production instances
   (jails with `ip4 = inherit`), give every engine its own ports and —
   for CouchDB — its own Erlang node name (`-name couchdb-qa@127.0.0.1`
   in `vm.args`; epmd registration is global).
2. The Continuum tree staged under a web root (`cp -a src/.` of the
   commit under test) with a QA `settings.ini` (two agent keys,
   QA engine ports).
3. Apache + mod_php (or PHP-FPM) serving `public/` on a free port.

Re-running QA after a change is: re-stage the tree, `apachectl graceful`
not needed (PHP re-reads files per request), run the smoke test.

CouchDB installs need `lang/erlang-runtimeNN` explicitly (the port does
not pull an Erlang runtime) plus: node-initialized system databases
(`_users`, `_replicator`), a `[admins]` entry in `local.ini`, and the
target `continuum_qa_*` databases created. ArcadeDB needs the first-boot
root password file and the QA database created via
`POST /api/v1/server {"command": "create database continuum_qa"}`.

Jails note: NFSv4 ACLs on the jail dataset mean a jail's root may lack
DAC override against pkg-installed ACLs — start services as root and run
`jexec` with root, or ACL-not-granted reads surface as `EACCES` in
engine logs. When a CouchDB/ArcadeDB falls over with `eacces` despite
"plausible" modes, check ACLs (`getfacl`) before modes.

## Release QA checklist

1. Poudriere build of the release candidate; install/upgrade in the QA
   target via pkg.
2. `make check-orphans` in the port directory before pushing plist
   changes.
3. `phpunit` on the exact tree under test.
4. `bin/smoke-test` (with `--key2`) against the QA `pkg`-installed copy.
5. Only then tag.
