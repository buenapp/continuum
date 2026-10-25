# Direct session injection (issue #13)

XMPP is Continuum's first-class real-time messaging transport. Direct
injection is an additional, opt-in ability for harness-specific preemption of
a live session, similar to the delivery Sonya performs natively across its
devices and sessions. It never replaces XMPP routing, fan-out, archive, or
audit behavior.

The implementation is in `Continuum\Injection\`:

- `InjectionManager` resolves operator configuration and performs liveness
  checks before delivery.
- `OpenHandsTransport` and `OpenCodeTransport` implement the two researched
  REST APIs.
- `TcpJsonTransport` implements the JSON-lines contract below for custom
  agents.
- `InjectionTransportInterface` is the seam for a future ACP client transport.
  ACP/stdio is not implemented: Devin currently has no live external injection
  endpoint, so configuring `acp` is a configuration error rather than a false
  success.

No agent can self-declare an arbitrary endpoint through `agent_register`.
Capabilities are operator-owned configuration, which prevents a compromised
agent key from turning Continuum into an SSRF client.

## Sending

The optional `preemption` argument accepts:

| Value | Meaning |
|---|---|
| `wakeup` | Inject and resume an idle session. |
| `steer` | Add context without resuming or interrupting the current turn. |
| `interrupt` | Interrupt a running turn, then inject and resume. An idle session is only injected and resumed. |

`preemption` requires `session`, either explicitly or derived from a task
bound to a session owned by the recipient. Without it, every send follows the
legacy path and does not even construct a direct-transport request.

When `[xmpp]` outbound is configured, Continuum sends over XMPP exactly as it
does without this feature. Only after the sidecar accepts that primary send
does the manager attempt supplemental direct injection. XMPP therefore keeps
its bare-JID fan-out to live resources, offline delivery, archive, and audit
semantics. XMPP failures remain fatal and are not hidden by a direct attempt.

Without XMPP, direct injection can be the live delivery path. If it is not
configured, is unreachable, or fails, the existing local inbox is used and the
result reports the fallback. A direct success in this configuration is not
also written to the local inbox, avoiding two copies.

The manager checks liveness before direct injection. A `running` or `idle`
result permits an attempt; `unreachable` skips both interrupt and injection.

`queued` and `pushed` are independent when XMPP is present: `queued` reports
the primary XMPP/inbox result, while `pushed` reports the supplemental direct
attempt. `via` names the primary path and `injection_via` names the successful
supplemental transport.

| Result | Meaning |
|---|---|
| `queued=true, via=xmpp-bridge, pushed=true, injection_via=...` | XMPP accepted the message and the additional live-session injection also succeeded. |
| `queued=true, via=xmpp-bridge, pushed=false, injection_reason=...` | XMPP accepted the message. The extra injection was unavailable or failed; this is not an XMPP fallback. |
| `queued=false, pushed=true, via=...` | No XMPP outbound is configured; the remote harness accepted the direct delivery. |
| `queued=true, pushed=false, via=inbox, fallback_reason=...` | No XMPP outbound is configured; direct delivery was unavailable or failed, so the local inbox was used. |
| `pushed=true, resumed=false` | OpenHands saved the message but returned HTTP 429 because run capacity was full. `remote_status` is 429. With XMPP configured, its independent `queued=true` result is still present. |

Direct-transport failures are caught only at the injection boundary and become
a reported supplemental failure or, without XMPP, a reported local-inbox
fallback. Existing XMPP failures still fail the send: configuring XMPP does
not silently convert its delivery contract into local-only mail.

## Configuration

Sections are named `[injection.<agent>]`, where `<agent>` is the Continuum
identity used by `message_send`. All sections are optional. An empty or
missing configuration means no direct transport and therefore normal queued
delivery. `[injection] enabled = false` disables every target without
removing its configuration.

Common target keys:

- `transport`: `openhands`, `opencode`, `tcp` (or `tcp-json`), `null`.
  `rest` is accepted with `driver = "openhands"` or `"opencode"`.
- `timeout`: request/connect/read timeout in seconds, 1 through 60. The
  global `[injection] timeout` defaults to 5; a target can override it.
- `session_resolution`: `message` (default), `fixed`, or `map`.
  - `message` uses the Continuum `session` argument as the harness ID.
  - `fixed` uses the target's `session_id` for every message.
  - `map` looks up the Continuum session in `session_map`, a JSON object
    whose values are non-empty harness IDs. There is no raw-ID fallback for
    an unmapped session.
- `ca_file`: optional CA bundle for HTTPS/TLS. Certificate verification is
  on; there is no `verify_tls = false` escape hatch.

REST targets require `base_url`, an `http://` or `https://` URL without
credentials, query, or fragment. Authentication is configured separately:

- `auth_scheme = "none"` (default), `basic`, `bearer`, or `header`.
- `credential_file` is required for every non-`none` scheme. It names a
  readable file containing one secret; only its trailing newline is removed.
  The secret is read when a request is made and is never included in audit
  events or tool results.
- `username` is used by `basic` (OpenCode defaults to `opencode`).
- `auth_header` is used by `header` (OpenHands defaults to
  `X-Session-API-Key`; other header transports default to `Authorization`).

OpenCode also accepts `payload = "text"` (the 2.0.x top-level `text` form,
default), `nested` (`prompt: {text: ...}`), or `parts` (the legacy
`parts`/`noReply` form). The setting is explicit because these wire formats
are not interchangeable. `parts` uses `/prompt_async` so a send does not wait
for an agent turn.

Example (also present, commented out, in `settings.ini.sample`):

```ini
[injection]
enabled = true
timeout = 5

[injection.opencode]
transport = "opencode"
base_url = "http://127.0.0.1:4555"
auth_scheme = "basic"
username = "opencode"
credential_file = "/var/db/continuum/opencode.password"
payload = "text"
session_resolution = "message"

[injection.openhands]
transport = "openhands"
base_url = "http://127.0.0.1:18000"
auth_scheme = "header"
auth_header = "X-Session-API-Key"
credential_file = "/var/db/continuum/openhands.key"
session_resolution = "map"
session_map = '{"continuum-session-id":"openhands-conversation-id"}'
```

The file paths are examples. Credentials and endpoint addresses belong to
deployment configuration, not to the public repository or issue reports.

## REST contracts

### OpenHands

- Liveness: `GET /api/conversations/{id}`. `execution_status` maps to
  `running` or `idle`; HTTP 404 maps to `unreachable`.
- Inject: `POST /api/conversations/{id}/events` with `role: "user"`,
  `content`, and `run`. `run` is false only for `steer`.
- Interrupt: `POST /api/conversations/{id}/interrupt` before injection when
  the pre-send state is running.

The current `SendMessageRequest` has no metadata member. The first content
item is the human body; the second is a JSON text item containing the complete
Continuum envelope. A consumer that needs machine fields can parse that second
item. This preserves `sig`, `cert`, `attest`, targeting, and the original body
without changing their values.

### OpenCode

- Existence/liveness: `GET /api/session/{id}`, then
  `GET /api/session/active`. A session in the active map is running; an
  existing session absent from it is idle. If `/active` is unavailable, the
  session record's status fields are used. If no status is exposed, liveness
  fails closed rather than guessing: XMPP still carries the message when
  configured, otherwise the local inbox is used. HTTP 404 for the session is
  unreachable.
- Inject: `POST /api/session/{id}/prompt` for `text` and `nested`, or
  `/prompt_async` for `parts`.
- Interrupt: `POST /api/session/{id}/interrupt` before injection when running.

For `text` and `nested`, `resume` is false for `steer` and true otherwise;
`steer` also sets `delivery: "steer"`. The `text` form carries the full
envelope at `metadata.continuum`. The `nested` schema has no metadata member,
so it uses a marked `<continuum-envelope>` JSON text trailer. The legacy
`parts` form uses the same second-text-item carrier as OpenHands.

## JSON-over-TCP contract

A `tcp` target requires `host` and `port`; `scheme = "tcp"` is the default and
`scheme = "tls"` enables verified TLS. Each connection carries one JSON object
followed by LF and one JSON object response followed by LF. Connections are
not shared between MCP requests. Responses are limited to 1 MiB and all I/O
has the configured timeout.

Requests:

```json
{"op":"liveness","session":"session-id"}
{"op":"interrupt","session":"session-id","id":"M-XXXXXXXX"}
{"op":"inject","session":"session-id","preemption":"wakeup","id":"M-XXXXXXXX","envelope":{"id":"M-XXXXXXXX","from":"alice","body":"..."}}
```

Liveness answers with `{"state":"running|idle|unreachable"}`. Interrupt
and inject must answer with an explicit `{"accepted":true}` (`ok` or
`success` may be used instead). A rejected or malformed answer is a failed
supplemental injection; XMPP remains delivered when configured, otherwise the
local inbox fallback is used. The `envelope` object is passed intact,
including identity carrier fields.

## XMPP and identity

XMPP remains configured under `[xmpp]`, not `[injection.*]`, and remains the
first-class real-time transport. Its sidecar API, bare-JID addressing,
resource fan-out, offline delivery, archive, and audit behavior are unchanged.
Direct injection is an additional per-session capability, not a routing
replacement and not a reason to bypass an XMPP send.

When XMPP is configured, the sidecar send happens first. The optional direct
attempt is made afterward, so a live harness can mimic Sonya's native session
preemption while all XMPP participants retain their normal copies. If the
extra attempt fails, `injection_reason` records that limitation without
turning the successful XMPP delivery into a fallback. If XMPP itself fails,
the call fails as before and direct injection does not conceal the failure.

Both paths carry the same Continuum envelope `id`. A consumer that listens to
both XMPP and direct injection must drop duplicate deliveries by that ID,
matching Sonya's archive and control-message rule. Configure direct injection
for harnesses that need its preemption semantics, not as an unconsidered
second live subscription.

Direct transports receive the envelope after Continuum has added `attest`, so
identity fields have exactly the same values as they would on the XMPP path.
Verification remains the receiver's responsibility as specified in
`docs/IDENTITY.md`; Continuum does not verify or rewrite carrier material.

## Scale model

This change adds no listener, daemon, persistent connection, or event loop.
Continuum remains its existing request-scoped PHP application (Apache worker
processes, or one stdio process). Each adapter is an outbound client owned by
one request, has no shared mutable state, and uses bounded time and response
budgets. The remote harness owns session queues and backpressure; Continuum's
existing XMPP path remains the primary real-time path, with the local inbox
as the fallback when XMPP is not configured. No new throughput or
connection-scale claim is made, and no long-lived ACP stream is hidden in a
PHP worker.
