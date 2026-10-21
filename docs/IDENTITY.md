# Verifiable sender identity (issue #6)

This document is the byte-exact spec for message identity in Continuum
v1: canonical envelope encoding, delegation certificates, end-to-end
signatures and Continuum attestation. Every future implementation signs
and verifies exactly the bytes defined here. The reference
implementation is `src/classes/Continuum/Identity/` (pure PHP +
ext-sodium) and `tests/IdentityTest.php` pins every vector below.

## 1. Trust levels

A receiver distinguishes three levels, highest trust first:

| Level | Carriers | Proves |
|---|---|---|
| `signed` | `sig` + `cert` | the message came from the session key delegated to a workstream and was not altered |
| `attested` | `attest` | Continuum itself stamped the envelope (its sender fields) for an MCP-only agent; trust rests on the Continuum server |
| `legacy` | none | nothing; the envelope makes no identity claims |

An envelope with `sig`/`cert` is decided on the end-to-end path only;
its `attest` (Continuum adds one on every configured send) is not
consulted. Incomplete material (`sig` without `cert` or vice versa) is
never `valid`.

## 2. Identity registry (read side)

Verification consumes one registry record per workstream (CouchDB db
`identities`, doc id = workstream name; written only by the operator CLI
`src/bin/identity-admin`):

```
{pubkey, status, created_at, rotated_at, revoked_at}
```

`pubkey` is the base64 raw 32-byte Ed25519 public key, `status` is
`active` or `revoked`, the dates are ISO-8601 (`rotated_at`/`revoked_at`
nullable). `Verify::envelope()` takes such a record as an array; callers
fetch it through the registry (`continuum://identities/<workstream>` or
the storage facade). When the record carries a name (`_id`), it must
match the workstream being verified.

## 3. Canonical JSON production

Produced for any covered field set as follows, in order:

1. Drop every field not in the covered set (see 4 and 6).
2. Recursively `ksort` every array, `SORT_STRING` (byte order of the
   key names; all v1 keys are lowercase ASCII).
3. `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
   No whitespace is added anywhere. Input and output are UTF-8.

All v1 covered values are strings, so the encoding only ever escapes
inside string values. Pinned behavior (see the vector in 7.4):
double quote and backslash escape as `\"` and `\\`; `/` is NOT escaped
as `\/`; `\n`, `\r`, `\t` use their short escapes; other control
characters use `\uXXXX`; non-ASCII characters are emitted literally as
UTF-8. The reference implementation adds `JSON_THROW_ON_ERROR`, so an
unecodable input (invalid UTF-8) throws instead of emitting garbage;
this flag does not change emitted bytes.

## 4. Envelope field set (v1)

| Field | In covered sets | Notes |
|---|---|---|
| `body` | always | message text |
| `from` | always | sender agent id, stamped from the authenticated API key |
| `id` | always | `M-` + 8 uppercase hex, assigned at send time |
| `session` | always | the sender-declared session, or `""` when undeclared |
| `to` | always | recipient agent id, send-side context |
| `ts` | always | ISO-8601 UTC, assigned at send time |
| `epoch` | when present | control traffic master epoch |
| `expires` | when present | ISO-8601 message expiry |
| `kind` | when present | notice, context, directive, control |
| `priority` | when present | normal, urgent |
| `replyTo` | when present | id of the message answered |
| `task` | when present | task id riding as reference or target |
| `topic` | when present | free-form topic |
| `sig` | see 6.2 / 6.3 | carrier field |
| `cert` | see 6.2 / 6.3 | carrier field |
| `attest` | see 6.2 / 6.3 | carrier field |

`to` never appears in the stored envelope: it is the send-side context.
A signer includes the recipient's agent id; a verifier supplies its own
agent id (or the original recipient's, when verifying on someone's
behalf). `session` is always present in a covered set: undeclared
sessions are signed as the empty string. Storage-only fields
(`lease`, `lease_until`, `via`, `stanza_id`, ...) never participate.

## 5. Carrier fields

`sig`, `cert` and `attest` are optional opaque base64 strings at the
MCP boundary: `message_send` shape-checks `sig`/`cert` (non-empty,
base64-decodable) and stores them unchanged; `attest` is computed by
Continuum (6.3). Reads (`message_lease`, `message_inbox_pull`) return
all three unchanged, and the XMPP bridge carries them in the sonya
payload (`urn:sonya:message:0` fields `sig`, `cert`, `attest`).
Verification is always the receiver's job; Continuum never rewrites or
strips carrier fields.

## 6. Signature schemes

All three signatures are raw Ed25519 (64 bytes), base64 when carried.

### 6.1 Delegation certificate (`cert`)

One opaque field holding a JSON string:

```
{not_after, scope?, session, session_pubkey, workstream, ws_sig}
```

- `session_pubkey`: base64 raw 32-byte Ed25519 public key of the session
- `workstream`: the workstream identity that delegated the key
- `session`: the session the key speaks for
- `not_after`: ISO-8601 expiry of the delegation (hours, not days)
- `scope`: optional free-form narrowing (allowed recipients, denied
  kinds); carried and signed but NOT enforced in v1 (reserved)
- `ws_sig`: base64 Ed25519 signature by the workstream key over the
  canonical encoding of the other fields

Exactly these keys are allowed: an unknown key is a schema violation,
so no unsigned field can ride along. `ws_sig` covers
`{not_after, scope?, session, session_pubkey, workstream}` (the cert
fields minus `ws_sig`) under the canonical rules of 3.

Certificate verification order, fixed:

1. schema (4 + this section's key set and types)
2. `ws_sig` over the canonical covered set with the workstream
   registry `pubkey`
3. workstream `status` is `active`
4. `not_after` is in the future

### 6.2 End-to-end signature (`sig`)

Signed by the session key (`cert.session_pubkey`) over the canonical
encoding of every v1 field present **except** `sig`, `cert` and
`attest`: `sig` cannot cover itself, and `cert`/`attest` are attached
alongside or after it by other parties (the delegating workstream and
Continuum). The envelope's `session` must equal `cert.session`; a
mismatch is a failed verification.

Verification chain: `sig` proves the message fields under
`session_pubkey`; `ws_sig` proves that key under the workstream key;
the registry record proves the workstream key.

### 6.3 Continuum attestation (`attest`)

For MCP-only senders Continuum signs instead: on every `message_send`
with `[identity] sign_key_file` configured, `attest` = base64 Ed25519
signature over the canonical encoding of every v1 field present
**except** `attest` itself (so `sig` and `cert` participate when the
sender attached them) **plus** one extra field `workstream` holding the
authenticated workstream. In v1 the authenticated workstream equals the
envelope's `from` (both are the agent behind the API key), which is how
a verifier reconstructs the covered set; the explicit field keeps the
claim pinned if the two ever diverge.

The signing key is Continuum's own; its public key is published in the
registry as workstream `continuum` and receivers verify `attest` with
that record (status `active`).

### 6.4 Covered sets at a glance

| Signature | Key | Covered set |
|---|---|---|
| `sig` | `cert.session_pubkey` | v1 fields present minus `sig`, `cert`, `attest` |
| `ws_sig` | registry `pubkey` of the cert's workstream | cert fields minus `ws_sig` |
| `attest` | registry `pubkey` of workstream `continuum` | v1 fields present minus `attest`, plus `workstream` |

## 7. Worked examples

The seeds below are public test vectors (also pinned in
`tests/IdentityTest.php`). Never use them for real keys.

```
workstream seed (32 bytes ASCII): continuum-workstream-key-seed-01
session seed    (32 bytes ASCII): continuum-session-key-seed-00000
attest seed     (32 bytes ASCII): continuum-attest-key-seed-000000

workstream pubkey (base64): oM5Oq3FFHpk+zsm8fL1TKYCMn1lM2FziK7IqOBbZePY=
session pubkey    (base64): fszv/eckulqJ+JodgNl0nnqo5/uchSS8s6n9CxS+oN8=
attest pubkey     (base64): YCRtP5KAQVdODEht1tVgZYVRIPvP+29IWCludNaZEv4=
```

### 7.1 Signing a message (`sig`)

Envelopes as stored (signer context adds `to` = `sonya`):

```
{id: M-1A2B3C4D, from: devin, body: ready for review,
 ts: 2026-10-21T02:03:04+00:00, kind: directive, session: sess-42}
```

Canonical covered set (3, 6.2):

```
{"body":"ready for review","from":"devin","id":"M-1A2B3C4D","kind":"directive","session":"sess-42","to":"sonya","ts":"2026-10-21T02:03:04+00:00"}
```

`sig` (session key):

```
++LNWcieroac3BVHFLi6Oj0Qq9WSty09Wi7n2WHMrbwkBFktdkQOgWgW+6MUExH+haBlZAKMMtzUcT48L5v6Ag==
```

```php
$covered = \Continuum\Identity\Envelope::sigCovered($envelope + ['to' => 'sonya']);
$message = \Continuum\Identity\Envelope::canonical($covered);
$sig = base64_encode(sodium_crypto_sign_detached($message, $sessionSecretKey));
```

### 7.2 Delegating a session key (`cert`)

(The vector `not_after` is far in the future so the pinned bytes stay
verifiable; real delegations expire in hours, see 9.)

```
{"not_after":"2126-10-21T08:03:04+00:00","session":"sess-42","session_pubkey":"fszv/eckulqJ+JodgNl0nnqo5/uchSS8s6n9CxS+oN8=","workstream":"devin"}
```

Canonical `ws_sig` input (3, 6.1):

```
{"not_after":"2126-10-21T08:03:04+00:00","session":"sess-42","session_pubkey":"fszv/eckulqJ+JodgNl0nnqo5/uchSS8s6n9CxS+oN8=","workstream":"devin"}
```

`ws_sig` (workstream key):

```
MEtDthuzzFogaP0fCCe8UoIVBT8tAvqigp5WXpT9/S+FNUGUehuEN8rQTPxmgDDOHKJzdFhlWJD/BdTdGnYLAQ==
```

Carried `cert` string (field order irrelevant to parsing):

```
{"not_after":"2126-10-21T08:03:04+00:00","session":"sess-42","session_pubkey":"fszv/eckulqJ+JodgNl0nnqo5/uchSS8s6n9CxS+oN8=","workstream":"devin","ws_sig":"MEtDthuzzFogaP0fCCe8UoIVBT8tAvqigp5WXpT9/S+FNUGUehuEN8rQTPxmgDDOHKJzdFhlWJD/BdTdGnYLAQ=="}
```

```php
$cert = ['not_after' => $notAfter, 'session' => $session,
         'session_pubkey' => base64_encode($sessionPublicKey),
         'workstream' => 'devin'];
$cert['ws_sig'] = base64_encode(sodium_crypto_sign_detached(
    \Continuum\Identity\Envelope::canonical(\Continuum\Identity\Cert::covered($cert)),
    $workstreamSecretKey));
$certField = json_encode($cert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```

### 7.3 Attesting an MCP-only sender (`attest`)

```
{id: M-2E3F4A5B, from: openhands, body: standup done,
 ts: 2026-10-21T02:04:05+00:00}        to = sonya
```

Canonical covered set (3, 6.3; note `session` defaults to `""` and
`workstream` is added):

```
{"body":"standup done","from":"openhands","id":"M-2E3F4A5B","session":"","to":"sonya","ts":"2026-10-21T02:04:05+00:00","workstream":"openhands"}
```

`attest` (workstream `continuum` key):

```
xnMydONBQCSCpTMSQOJNgGja+GO2FHH5ZYIElpbfOF13DfKDLLJ+Diu1gpxMS+uEG6ljD42J4E+mG7t/SHJxBA==
```

### 7.4 Escaping vector

Input `body` = `quote " slash / nl <LF> tab <TAB> unicode é 🚀` with
`from=x`, `id=M-00000000`, `to=y`, `ts=2026-10-21T00:00:00+00:00`,
undeclared session:

```
{"body":"quote \" slash / nl \n tab \t unicode é 🚀","from":"x","id":"M-00000000","session":"","to":"y","ts":"2026-10-21T00:00:00+00:00"}
```

## 8. Verification procedure

`\Continuum\Identity\Verify::envelope(array $envelope, array $identityDoc)`
never throws for envelope problems. `$envelope` is the received envelope
plus `to`; `$identityDoc` is the registry record of the key that must
verify the outermost signature (the cert's workstream for `signed`,
workstream `continuum` for `attested`, anything for `legacy`). It
returns:

```
['level' => 'signed'|'attested'|'legacy',
 'valid' => bool,
 'workstream' => ?string,   // cert workstream, or the attested `from`
 'session' => ?string,      // cert session, or the attested declared one
 'reason' => ?string]       // failing step when valid is false
```

Procedure:

1. If exactly one of `sig`/`cert` is present: `signed`, invalid.
2. Both present: parse `cert` (6.1 schema), run the certificate
   verification order of 6.1, require `session` == `cert.session`,
   then verify `sig` over the canonical covered set of 6.2 with
   `cert.session_pubkey`. Success: `signed`, valid.
3. Neither present but `attest` present: verify `attest` over the
   canonical covered set of 6.3 with the workstream `continuum`
   registry key (name-checked when the record carries one; status
   `active`). Success: `attested`, valid.
4. No carriers: `legacy`, valid. `identityDoc` is not consulted.

## 9. Expiry and revocation rules

- **Certificate expiry.** `not_after` bounds a session key: at or after
  that instant every `sig` under it fails. Comparison uses the
  verifier's clock (`>=` fails); v1 allows no clock-skew grace. Keep
  delegations short (hours): a leaked session key is useful only until
  `not_after`.
- **Message expiry.** `expires` bounds delivery as before: expired
  messages are dropped from reads and are not a verification matter.
- **Revocation.** Setting a workstream's `status` to `revoked` fails
  the status gate for every certificate it signed, immediately and
  regardless of `not_after`. The record is kept (never deleted) so the
  signatures stay checkable for audit and pre-revocation certificates
  can still be evaluated against their own `not_after`.
- **Rotation.** The record holds only the current `pubkey`.
  Certificates signed by the previous key stop verifying against it and
  must be re-delegated.
- **Attestation.** `attest` carries no expiry of its own; its trust is
  exactly the registry record of workstream `continuum` (active or
  revoked).

## 10. Key material and settings

`[identity] sign_key_file` names a file with raw sodium Ed25519 key
material: either the 64-byte secret key (seed || public key) or the
full 96-byte `sodium_crypto_sign_keypair()` keypair. (Both are accepted
because the cross-track contract says "raw 64-byte keypair
(sodium_crypto_sign_keypair format)" and those two statements describe
different sodium layouts; the public key is derivable from either and
goes into the registry as workstream `continuum`.) The key is loaded
once per process. Unset setting = attestation disabled and every send
is byte-identical to a pre-identity Continuum.

Workstream private keys never live in Continuum: `identity-admin
keygen` writes them for the operator, who stores them outside the
server (e.g. OpenBao) and publishes only `pubkey` in the registry.

## 11. Send-time fields

`id` and `ts` are assigned by `message_send` at send time. `sig` covers
them as stored (6.2), so a signer that computes `sig` before the send
call cannot produce those two values; the current send surface has no
id/ts passthrough. End-to-end signing therefore applies to envelopes
whose final `id`/`ts` were known to the signer; extending `message_send`
to accept them (or a sign-and-send tool) is tracked as follow-up.
`attest` has no such gap: Continuum signs after stamping `id`/`ts`.

## 12. Proved versus declared session (v1)

v1 carries no proof machinery: a `session_proof` field stays absent and
absent means declared. `cert.session` proves a session only for the
signing key itself (6.2); `_meta.session` claims on leases and task
claims remain declared. Full proved-session marking follows after
attestation lands.

## 13. Compatibility

- Legacy envelopes (no `sig`/`cert`/`attest`) are valid `legacy` and
  keep exactly today's schema and bytes.
- Reads and `MESSAGE_SCHEMA` gain `sig`, `cert`, `attest` as optional
  string properties; nothing else changes shape.
- With `[identity] sign_key_file` unset, `message_send` output and the
  stored envelope are byte-identical to before this feature.
