# XMPP Transport (issue #3) - design proposal

Status: proposal, pending operator decision. Nothing in this document is
wired into the server yet; issue #2 (targeting + lease/ack) is the only
messaging change currently implemented, and an XMPP-less Continuum keeps
exactly that behavior.

## Question

`message_send` should optionally carry messages over XMPP (on xmppd),
with an agent's inbox backed by its JID. The integration point can be:

1. a **Zig PHP extension** linked into Continuum's Apache/PHP runtime, or
2. a **Zig sidecar daemon** that holds the XMPP stream and talks to
   Continuum over local IPC.

## Recommendation: sidecar

XMPP client connectivity is an always-on workload: one long-lived TLS
stream per account, XEP-0198 stream management state tied to that TCP
connection, unsolicited inbound stanzas arriving between HTTP requests,
and presence that must not flap. Continuum's PHP is request-scoped:
each Apache worker is a separate process that exists for one MCP call.
An in-process extension would mean one XMPP connection per worker,
presence churn on worker recycling, and no receive path while PHP is
idle. It would also mean maintaining a Zend-ABI binding across every
supported PHP version for no functional gain.

A sidecar built on xmppd's `lib/xmppc` (the estate's Zig XMPP client
core) holds the stream independently of PHP's lifecycle. This matches
the existing decision for the other PHP consumer in the estate
(SecureMessage chat's BFF, 2026-09-29): PHP talks to a small local
bridge over IPC, never to raw XMPP. One client core, one bridge
pattern, all consumers.

Continuum remains the system of record: the board, tasks, locks,
handoffs, the lease model, and the audit log live here; the sidecar is
a pipe.

## How the sidecar works

It is **not** a proxy. A proxy would relay XMPP protocol bytes between
Continuum and xmppd, which would put the XMPP state machine back inside
PHP, the thing we are avoiding. The sidecar *is* the XMPP client:

- **It signs in, not Continuum.** Continuum has no XMPP stack and never
  sees an XMPP credential. The sidecar holds one c2s account per
  configured agent identity (`devin@…`, `sonya@…`, `continuum@…` for
  the service itself), authenticates (SASL), binds the resource,
  publishes presence, and keeps the TLS stream up forever, reconnecting
  and resuming with XEP-0198 after outages. `lib/xmppc` is explicitly
  built for N-clients-one-kqueue, so N streams cost one kqueue loop.
- **It owns all stream state.** Stream-management ack counts, resumption
  tokens, presence, roster, MAM archive sync cursors. If xmppd or the
  network blips, the sidecar absorbs it; a message Continuum already
  "sent" is still delivered once the stream resumes.
- **Inbound is push, not poll.** Stanzas arrive on the always-on stream
  the moment xmppd routes them (including offline-store delivery at
  sign-in). The sidecar maps each stanza to the agent whose account
  received it and POSTs it to Continuum's loopback inbound hook, which
  files it into that agent's inbox through the normal `message_send`
  code path (audit log included).
- **Continuum's PHP stays request-scoped and stateless.** `message_send`
  does one local socket round-trip: hand the sidecar a JSON envelope,
  get back a stanza id. Nothing persists in PHP between requests, which
  is exactly what Apache's process model wants.

What Continuum configures is only: the socket path, which local agent
identities map to which JIDs, and the HTTP credential the sidecar
presents on the inbound hook. The XMPP account passwords live in the
sidecar's own config, not in Continuum's.

Failure modes degrade to issue-#2 semantics: sidecar down or
unconfigured → targeted inbox only, no data loss (sends either fail
loud or fall back to local inbox delivery, configurable).


## Architecture

```
            +------------+   c2s/TLS (XEP-0198, MAM)   +--------+
            |   xmppd    |<--------------------------->| sidecar |
            +------------+                             +--------+
                                                           |  ^
                        unix socket HTTP/JSON (own socket) |  | POST /mcp/xmpp-inbound
                                                           v  | (loopback vhost, agent key)
            +-----------------------------------------------------+
            |                    Continuum (PHP)                  |
            |   message_send -> bridge client -> sidecar          |
            |   inbound hook  -> inbox push + audit log           |
            +-----------------------------------------------------+
```

- **Outbound**: `message_send` (and derived payloads) posts to the
  sidecar over a unix-domain socket. Fire-and-report: the sidecar
  answers once the stanza entered the stream's unacked queue; XEP-0198
  acks and resume live entirely inside the sidecar.
- **Inbound**: the sidecar posts received stanzas to a loopback-only
  Continuum endpoint carrying the recipient agent key, which pushes the
  envelope into the target inbox through the same code path as
  `message_send`, so audit logging stays single-sourced.
- **Optional**: no `[xmpp]` section (or an empty one) means no sidecar
  calls; the targeted-inbox behavior from issue #2 is the whole story.

## Addressing

Issue #3's addendum (multiple Sonya Core nodes) fixes the shape:

- One XMPP account per logical mesh (`sonya@<xmpp-domain>`), one
  credential per node for isolated revocation; agent identities get
  their own accounts.
- A live session is a **resource** of that account (`sess_<hex>`);
  `node-<name>` identifies a node; session ids carry no node name so a
  move keeps the address.
- Mail for the whole mesh goes to the bare JID; only the master node
  reads it, by presence priority (master highest, others negative).
- Continuum addresses sessions by full JID and always includes the
  structured payload; receivers route by payload and forward mail for
  sessions they do not hold.

## API shape (sidecar, v0)

Plain HTTP/1.1 + JSON on a unix socket; no TLS inside the node.

```
PUT  /v1/send
     { "to": "full-or-bare-jid",
       "body": "human-readable text",
       "sonya": { "kind": "notice|context|directive|control",
                  "session": "...", "task": "T-XXXX",
                  "expires": "ISO-8601", "priority": "normal|urgent",
                  "epoch": "..." },          # control only
       "thread": "parent message id" }      # maps to <thread/>
  -> { "id": "stanza id", "queued": true }

GET  /v1/health     -> { "state": "online|connecting|offline",
                         "sm_unacked": n, "uptime": seconds }
GET  /v1/presence   -> roster/presence snapshot (ops/debug)
```

Inbound hook on Continuum (loopback vhost only):

```
POST /mcp/xmpp-inbound   (Authorization: Bearer <agent key>)
     { "from": "full-jid", "body": "...",
       "sonya": { ... mapping of urn:sonya:message:0 fields ... },
       "thread": "...", "stanza_id": "...", "archive_id": "..." }
  -> routes into the recipient's inbox; identical audit trail to
     message_send
```

Stanza mapping (outbound): `<body>`, one
`<sonya xmlns="urn:sonya:message:0" kind=... session=... task=...
expires=... priority=... epoch=.../>`, `<thread>` for replies.
Server-stamped stanza id replaces the local one when XEP-0359 is
available; `id` correlation falls back to the stanza id otherwise.

## xmppd facts (checked against source, 2026-10-01)

- **XEP-0114 (external components): not implemented.** No component
  accept listener exists; Continuum and agents connect as ordinary c2s
  client accounts. A component profile could come later (fewer
  connections, no roster semantics), but nothing in this design depends
  on it.
- **Negative presence priority** excludes a resource from bare-JID
  message delivery (RFC 6121 §8.5.2.1.1) - verified in the router. Note
  the corollary: bare-JID mail goes to *every* non-negative resource,
  so role addressing relies on non-master nodes keeping negative
  priority.
- **Resource conflict**: a re-bind evicts a stale same-worker resource
  (RFC 6120 §7.7.3 takeover). Cross-worker takeover is not implemented
  yet (xmppd T154, targeted for v0.9.0); until then a session moving
  nodes must go through XEP-0198 termination first, not a cold re-bind.
- **Hard cap**: 16 resources per bare JID, with silent bind desync past
  the cap (xmppd T198, targeted for v0.9.0). The Sonya resource plan
  stays well under it.
- **Offline store + MAM (XEP-0313) exist**, so an MCP-only agent's
  JID-backed inbox is real: offline delivery on next connect, archive
  paging for history.

## Open items before implementation

1. Operator sign-off on sidecar-over-extension (this document).
2. `lib/xmppc` availability: the client core is WIP on xmppd's
   `feature/xmppc` branch (OpenHands session); the sidecar is its
   second consumer (after the T32 load driver), which per the 2026-09-29
   decision is when `xmppc` extracts to its canonical repo.
3. Settings surface (`[xmpp]` enable/account/socket path) and the
   loopback inbound endpoint; both additive, no breaking change.
