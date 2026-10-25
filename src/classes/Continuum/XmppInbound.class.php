<?php

namespace Continuum;

use Continuum\Storage\ContinuumStorage;

/**
 * Inbound hook for the XMPP bridge sidecar (issue #3).
 *
 * The sidecar (a Zig daemon holding the always-on c2s streams) POSTs one
 * JSON document per received message stanza to POST /mcp/xmpp-inbound,
 * authenticated with the bridge's own agent key. We map it onto the
 * idempotent inbox path (envelope + audit log) so a durable copy written by
 * message_send and a later XMPP copy share one ID, and duplicate stanzas do
 * not create duplicate inbox entries.
 *
 * Payload:
 *   agent    string   target agent identity (inbox owner)
 *   from     string   sender full JID
 *   body     string   message text
 *   topic    ?string  free-form topic hint
 *   thread   ?string  XEP-0201 <thread/>; becomes replyTo
 *   stanza_id ?string wire id for correlation
 *   sonya    ?object  urn:sonya:message:0 fields: kind, session, task,
 *                     priority, expires, epoch, sig, cert, attest, preemption
 *
 * Authorization: the calling agent key must equal [xmpp] bridge_agent;
 * a stanza without a configured bridge is rejected, and issue #3 stays
 * strictly optional (no [xmpp] config = no bridge path).
 */
class XmppInbound {

    public function __construct(private ContinuumStorage $storage, private string $bridgeAgent) {}

    /** Handle one decoded hook payload; returns [statusCode, responseArray]. */
    public function handle(string $callerAgent, mixed $payload): array {
        if ($this->bridgeAgent === '' || $callerAgent !== $this->bridgeAgent) {
            return [403, ['error' => 'inbound XMPP hooks are only accepted from the configured bridge identity']];
        }
        if (!is_array($payload)) {
            return [400, ['error' => 'invalid JSON body']];
        }
        $target = $payload['agent'] ?? null;
        $from = $payload['from'] ?? null;
        $body = $payload['body'] ?? null;
        if (!is_string($target) || $target === '' || !is_string($from) || $from === '' || !is_string($body)) {
            return [400, ['error' => 'agent, from and body are required strings']];
        }
        $sonya = (is_array($payload['sonya'] ?? null)) ? $payload['sonya'] : [];

        $providedId = $sonya['id'] ?? $payload['stanza_id'] ?? null;
        $envelopeId = is_string($providedId) && preg_match('/^M-[0-9A-F]{8}$/i', $providedId)
            ? strtoupper($providedId)
            : 'M-' . strtoupper(bin2hex(random_bytes(4)));
        $envelope = [
            'id' => $envelopeId,
            'from' => "xmpp:{$from}",
            'body' => $body,
            'ts' => gmdate('c'),
            'via' => 'xmpp',
        ];
        if (is_string($payload['topic'] ?? null)) { $envelope['topic'] = $payload['topic']; }
        if (is_string($payload['thread'] ?? null)) { $envelope['replyTo'] = $payload['thread']; }
        if (is_string($payload['stanza_id'] ?? null)) { $envelope['stanza_id'] = $payload['stanza_id']; }
        foreach (['kind', 'session', 'task', 'priority', 'expires', 'epoch', 'sig', 'cert', 'attest', 'preemption'] as $f) {
            if (is_string($sonya[$f] ?? null)) { $envelope[$f] = $sonya[$f]; }
        }

        $write = $this->storage->inboxPushOnce($target, $envelope);
        $duplicate = !$write['inserted'];
        $this->storage->appendLog($callerAgent, 'message_xmpp_inbound', [
            'id' => $envelope['id'], 'to' => $target, 'from' => $from,
            'session' => $envelope['session'] ?? null, 'task' => $envelope['task'] ?? null,
            'kind' => $envelope['kind'] ?? null, 'stanza_id' => $envelope['stanza_id'] ?? null,
            'preemption' => $envelope['preemption'] ?? null, 'duplicate' => $duplicate,
            'length' => strlen($body),
        ]);
        return [200, ['queued' => true, 'depth' => $write['depth'], 'id' => $envelope['id'], 'to' => $target, 'duplicate' => $duplicate]];
    }
}
