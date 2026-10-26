# Direct session injection (issue #13)

XMPP is the first-class real-time transport. The local inbox is the durable,
email-like record. Direct injection is an additional harness-specific ability
for preempting a live session, similar to Sonya's native behavior across its
devices and sessions. No path replaces another, and every accepted message has
one durable inbox copy.

The implementation is in `Continuum\Injection\`:

- `InjectionManager` resolves operator configuration, checks sender policy and
  signature trust, and performs liveness-gated delivery.
- `OpenHandsTransport` and `OpenCodeTransport` implement the researched REST
  APIs through the vendored `Enchilada\Tortilla\HttpClient` over canonical
  Extras `EnchiladaMultiHTTP`/`EnchiladaCurl`.
- `TcpJsonTransport` implements one atomic JSON-lines operation for custom
  agents.
- `InjectionTransportInterface` is the seam for a future ACP client transport.
  ACP/stdio is not implemented: Devin currently has no live external injection
  endpoint, so configuring `acp` is a configuration error rather than a false
  success.

HTTP execution is delegated to Tortilla and Extras. Continuum's
`RestTransport` only supplies authentication and harness-specific endpoint,
liveness, and payload rules. Extras `REST/` is an embedded HTTP server/router,
not an outbound client, so it is not used for these calls.

No agent can self-declare an arbitrary endpoint through `agent_register`.
Capabilities are operator-owned configuration, which prevents a compromised
agent key from turning Continuum into an SSRF client.

## Preemption is a receiver-policy request

The optional `preemption` argument accepts `wakeup`, `steer`, and `interrupt`.
It requires `session`, either explicitly or derived from a task bound to a
session owned by the recipient. It is attached to the envelope **before**
Continuum adds `attest`, so the request is covered by end-to-end signatures and
attestations. It is also forwarded in the XMPP `sonya` fields and returned on
inbox reads.

The sender requests a behavior; it does not get to impose it on a native
receiver. Sonya applies its own policy:

| Mode | Sonya equivalent | Receiver gate |
|---|---|---|
| `steer` | Deliver between tool rounds of a running turn. `priority=urgent` without `preemption` remains the compatibility spelling. | The message kind's sender allowlist. |
| `wakeup` | Start a turn on an idle session. | `[messages] wake` names the sender; default deny because it starts unattended work. |
| `interrupt` | `control: cancel`, then deliver. | Control-kind allowlist and signed control trust. `kind=control` is required. |

Sonya is a native receiver over XMPP and the inbox; it is not configured as an
`[injection.*]` target. `native_receivers` in the global `[injection]` section
is a comma-separated list of agent IDs whose native policy should be reported
as `preemption_via = "native"`. This is a declaration of the result path, not a
claim that the native receiver accepted or acted on the request.

For harnesses without their own policy, Continuum enforces the equivalent
policy before making a direct request:

- `wakeup_from`, `steer_from`, and `interrupt_from` in
  `[injection.<agent>]` are comma-separated sender allowlists. Every list is
  deny-by-default. An empty or missing list allows nobody.
- `interrupt` requires `kind=control`.
- `require_signed` defaults to false for `wakeup` and `steer`, and true for
  `interrupt`. When true, Continuum requires an end-to-end `sig`/`cert`
  chain (`Verify::LEVEL_SIGNED`); Continuum's own `attest` does not satisfy
  that requirement. The verified certificate workstream must match the
  authenticated sender and appear in the mode's allowlist. Explicitly setting
  it false for `interrupt` is an operator escape hatch, not the recommended
  control policy. Any carrier material presented is verified even when the
  mode does not require signing; valid attestation is acceptable only for a
  mode that does not require end-to-end proof.
- Native receivers perform their own equivalent verification and policy
  decisions. A failed Continuum direct-policy check does not remove the
  durable message from the native path.

## Delivery order and results

The order is deliberate:

1. Write the durable inbox copy once with `inboxPushOnce`.
2. If XMPP outbound is configured, send it with unchanged routing, bare-JID
   fan-out, archive, and fail-loud behavior.
3. Only then attempt the supplemental direct transport.

If XMPP fails, the call still fails and no direct attempt hides that failure;
the durable inbox copy remains. Direct injection is never used to bypass XMPP.
A direct success is also never used to suppress the inbox.

`queued` and `pushed` are independent: `queued` reports the durable inbox
result, while `pushed` reports the supplemental direct attempt. `via` names the
primary path (`inbox` or `xmpp-bridge`), `injection_via` names a successful
supplemental transport, and `preemption_via` reports `native`, the transport,
or `none` for the requested preemption path.

| Result | Meaning |
|---|---|
| `queued=true, pushed=true, via=inbox, injection_via=...` | No XMPP outbound is configured. The inbox was written and the direct transport accepted the additional delivery. |
| `queued=true, pushed=true, via=xmpp-bridge, injection_via=...` | XMPP accepted the real-time delivery and the additional direct delivery also succeeded. |
| `queued=true, pushed=false, via=xmpp-bridge, preemption_via=native|none, injection_reason=...` | XMPP and the inbox are intact, but the supplemental direct attempt was unavailable, denied, or failed. This is not an XMPP fallback. |
| `queued=true, pushed=false, via=inbox, preemption_via=native|none, fallback_reason=...` | No XMPP outbound is configured; the direct attempt failed or was unavailable, but the durable inbox remains. |
| `pushed=true, resumed=false` | OpenHands saved the message but returned HTTP 429 because run capacity was full. `remote_status` is 429 and the inbox is still present. |

When a REST interrupt succeeds but the following injection fails, the result
also contains `interrupted=true` and says that the remote session was
interrupted before the failure. TCP receivers perform cancel-and-deliver
atomically, so this split state cannot occur there.

## Configuration

Sections are named `[injection.<agent>]`, where `<agent>` is the Continuum
identity used by `message_send`. All sections are optional. An empty or
missing configuration means no direct transport. `[injection] enabled = false`
disables every direct target without removing its configuration.

Global keys:

- `enabled`: enable direct targets, default true.
- `native_receivers`: comma-separated native receiver agent IDs, default empty.
- `timeout`: **total** liveness-plus-injection budget in seconds, 1 through 60,
  default 5. The same absolute deadline is shared by all REST calls in one
  attempt, rather than giving each call a fresh timeout.

Common target keys:

- `transport`: `openhands`, `opencode`, `tcp` (or `tcp-json`), `null`.
  `rest` is accepted with `driver = "openhands"` or `"opencode"`.
- `timeout`: target-specific total budget, overriding the global value.
- `session_resolution`: `message` (default), `fixed`, or `map`.
  - `message` uses the Continuum `session` argument as the harness ID.
  - `fixed` uses the target's `session_id` for every message.
  - `map` looks up the Continuum session in `session_map`, a JSON object
    whose values are non-empty harness IDs. There is no raw-ID fallback for
    an unmapped session.
- `wakeup_from`, `steer_from`, `interrupt_from`: sender allowlists described
  above.
- `require_signed`: signature policy described above.
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
native_receivers = "sonya"

[injection.opencode]
transport = "opencode"
base_url = "http://127.0.0.1:4555"
auth_scheme = "basic"
username = "opencode"
credential_file = "/var/db/continuum/opencode.password"
payload = "text"
session_resolution = "message"
wakeup_from = "devin,opencode"
steer_from = "devin,opencode"
interrupt_from = "sonya"
require_signed = true

[injection.openhands]
transport = "openhands"
base_url = "http://127.0.0.1:18000"
auth_scheme = "header"
auth_header = "X-Session-API-Key"
credential_file = "/var/db/continuum/openhands.key"
session_resolution = "map"
session_map = '{"continuum-session-id":"openhands-conversation-id"}'
wakeup_from = "devin,opencode"
steer_from = "devin,opencode"
interrupt_from = "sonya"
require_signed = true
```

The file paths are examples. Credentials and endpoint addresses belong to
deployment configuration, not to the public repository or issue reports.

## REST contracts

### OpenHands

- Liveness: `GET /api/conversations/{id}`. `running` maps to running;
  `idle`, `paused`, `finished`, `error`, and `stuck` map to idle because the
  conversation can receive or resume work. `waiting_for_confirmation` and
  `deleting` map to unreachable so a blocked or disappearing conversation is
  not preempted. HTTP 404 is unreachable.
- Inject: `POST /api/conversations/{id}/events` with `role: "user"`,
  `content`, and `run`. `run` is false only for `steer`.
- Interrupt: `POST /api/conversations/{id}/interrupt` before injection when
  the pre-send state is running. If the later injection fails,
  `interrupted=true` is reported.

The current `SendMessageRequest` has no metadata member. The first content
item is the human body; the second is a JSON text item containing the complete
Continuum envelope. A consumer that needs machine fields can parse that second
item. This preserves `preemption`, `sig`, `cert`, `attest`, targeting, and the
original body without changing their values.

### OpenCode

- Existence/liveness: `GET /api/session/{id}`, then
  `GET /api/session/active`, sharing one deadline. A session in the active map
  is running; an existing session absent from it is idle. If `/active` is
  unavailable, the session record's status fields are used. If no status is
  exposed, liveness fails closed rather than guessing. HTTP 404 for the
  session is unreachable.
- Inject: `POST /api/session/{id}/prompt` for `text` and `nested`, or
  `/prompt_async` for `parts`.
- Interrupt: `POST /api/session/{id}/interrupt` before injection when running.
  A later failure reports `interrupted=true`.

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
uses the shared operation deadline.

Requests:

```json
{"op":"liveness","session":"session-id"}
{"op":"inject","session":"session-id","preemption":"interrupt","id":"M-XXXXXXXX","envelope":{"id":"M-XXXXXXXX","from":"alice","body":"...","kind":"control","preemption":"interrupt"}}
```

Liveness answers with `{"state":"running|idle|unreachable"}`. Inject must
answer with an explicit `{"accepted":true}` (`ok` or `success` may be used
instead). There is intentionally no separate `interrupt` request: the receiver
verifies the full envelope before it performs cancel-and-deliver in its own
turn machinery. It may include `interrupted` in its acknowledgement. A
rejected or malformed answer is a failed supplemental injection; the durable
inbox remains and XMPP remains delivered when configured.

## XMPP, inbox, and identity

XMPP remains configured under `[xmpp]`, not `[injection.*]`, and remains the
first-class real-time transport. Its sidecar API, bare-JID addressing,
resource fan-out, offline delivery, archive, and audit behavior are unchanged.
The companion `continuum-xmpp-bridge` forwards `preemption`, `sig`, `cert`,
and `attest` in both directions; the bridge does not execute preemption.

`inboxPushOnce` uses one atomic O(1) ValKey script: `SET inbox-seen:<agent>:<id>
1 NX EX 2592000` followed by `RPUSH` of the inbox entry. The 30-day seen
marker remains after acknowledgement, so a late XMPP copy cannot be
reinserted after the original was acked. It also avoids whole-list
`WATCH`/`DEL` rewrites and their contention failures. A duplicate hook returns
`duplicate=true` and does not add a second copy. An external signer may supply
`id` and `ts` to make the final signed envelope known in advance; a duplicate
supplied ID returns `duplicate=true` and does not repeat XMPP or direct
delivery. This is what lets the durable inbox, XMPP real-time copies, and
direct preemption copies coexist. Receivers that listen to more than one path
must also drop duplicates by envelope ID, matching Sonya's archive and
control-message rule.

Direct transports receive the envelope after Continuum has added `attest`, so
identity fields have exactly the same values as they would on the XMPP path.
`preemption` is included in the signed and attested covered sets. Verification
remains the receiver's responsibility as specified in `docs/IDENTITY.md`;
Continuum does not rewrite or strip carrier material.

## DANE and deployment

For this change, plain HTTP and HTTPS/TLS with the existing CA/system-store
validation are supported, as explicitly accepted by the operator on
2026-10-25. DANE TLSA-first validation is a separate shared-Extras follow-up
(`T-78EF8F46`); no Continuum-specific partial TLSA validator is introduced.
There is no restriction to plain HTTP and no claim that DANE is implemented
here.

## Scale model

This change adds no listener, daemon, persistent connection, or event loop.
Continuum remains its existing request-scoped PHP application (Apache worker
processes, or one stdio process). Each adapter is an outbound client owned by
one request, has no shared mutable state, and uses the shared operation
deadline and response budgets. Under Apache mod PHP no EventLoop or Fiber is
injected, so Tortilla uses its blocking `EnchiladaCurl::perform()` path; no
worker pool or cross-request state is required. `EnchiladaWorkerHTTP` is only
needed for an optional reactor/stdio composition. The remote harness owns
session queues and backpressure; Continuum's XMPP and inbox paths remain
independent of direct injection. No new throughput or connection-scale claim
is made, and no long-lived ACP stream is hidden in a PHP worker.
