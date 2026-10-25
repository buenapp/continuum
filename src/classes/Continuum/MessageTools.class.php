<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use Continuum\Identity\Attestor;
use Continuum\Injection\InjectionManager;
use Continuum\Storage\ContinuumStorage;
use Continuum\Bridge\XmppBridgeInterface;

/**
 * Agent-to-agent messaging on per-agent inboxes (ValKey lists).
 *
 * The inbox is the durable, email-like record and is written once on every
 * send. XMPP adds first-class real-time delivery; direct injection is an
 * optional supplemental path. `preemption` is attached to the envelope before
 * attestation and is a request for the receiver's Sonya-style policy, not an
 * instruction Continuum executes on a native receiver's behalf. Sends,
 * leases and acknowledgements are logged; pulls are not (assumed read-mostly
 * churn). Messages may carry targeting fields:
 *   session  - only a reader on that session (or message_lease scoped
 *              to it) receives it
 *   task     - resolved at send time to the session bound to the task
 *              when the recipient is the task's owner; otherwise the
 *              task rides as a reference and no session is derived
 *   kind     - notice | context | directive | control
 *   priority - normal | urgent
 *   expires  - ISO-8601; expired messages are dropped from reads
 *   replyTo  - id of the message being answered
 *   epoch    - control traffic only: the master epoch the sender acts
 *              under, so receivers drop stale-epoch directives
 *
 * Identity fields (issue #6, docs/IDENTITY.md) are optional opaque
 * carriers forwarded unchanged end to end:
 *   sig      - base64 session-key signature over the canonical envelope
 *   cert     - JSON delegation certificate binding that key to a workstream
 *   attest   - Continuum's own signature, attached per send when
 *              [identity] sign_key_file is configured
 *   preemption - receiver-policy request, included in covered signatures
 *
 * message_lease/message_ack are the non-destructive read path: a lease
 * marks messages in place until it expires, then they become eligible
 * again. The legacy message_inbox_pull still deletes on read, but only
 * ever touches untargeted, unleased, unexpired messages.
 */
class MessageTools {

    private const KINDS = ['notice', 'context', 'directive', 'control'];
    private const PRIORITIES = ['normal', 'urgent'];
    private const PREEMPTIONS = ['wakeup', 'steer', 'interrupt'];

    private ?Attestor $attestor = null;

    public function __construct(
        private ContinuumStorage $storage,
        private ?XmppBridgeInterface $xmppBridge = null,
        private ?string $xmppDomain = null,
        ?string $signKeyFile = null,
        private ?InjectionManager $injection = null,
    ) {
        // Attestation key material loads once per process; an unset
        // [identity] sign_key_file leaves the send path untouched.
        if ($signKeyFile !== null && $signKeyFile !== '') {
            $this->attestor = Attestor::fromFile($signKeyFile);
        }
    }

    #[McpTool(
        name: 'message_send',
        description: 'Send a message to another agent. Optional session/task targeting, kind (notice|context|directive|control), priority (normal|urgent), expires (ISO 8601), replyTo, epoch (control only), sig/cert, preemption (wakeup|steer|interrupt; session required; interrupt requires kind=control), and id/ts for externally signed envelopes.',
        outputSchema: self::SEND_SCHEMA
    )]
    public function message_send(
        string $to,
        string $body,
        ?string $topic = null,
        ?string $session = null,
        ?string $task = null,
        ?string $kind = null,
        ?string $priority = null,
        ?string $expires = null,
        ?string $replyTo = null,
        ?string $epoch = null,
        ?string $sig = null,
        ?string $cert = null,
        ?string $preemption = null,
        ?string $id = null,
        ?string $ts = null,
    ): ToolResult {
        if ($to === '') { throw new \RuntimeException('recipient must not be empty'); }
        if ($id !== null && !preg_match('/^M-[0-9A-F]{8}$/', $id)) {
            throw new \InvalidArgumentException('id must match M-XXXXXXXX (8 uppercase hex digits)');
        }
        if ($ts !== null && ($ts === '' || strtotime($ts) === false)) {
            throw new \InvalidArgumentException('ts must be a non-empty ISO-8601 timestamp');
        }
        if ($preemption !== null && !in_array($preemption, self::PREEMPTIONS, true)) {
            throw new \InvalidArgumentException('invalid preemption; expected one of: ' . implode(', ', self::PREEMPTIONS));
        }
        if ($preemption === 'interrupt' && $kind !== 'control') {
            throw new \InvalidArgumentException('interrupt requires kind=control');
        }
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('invalid kind; expected one of: ' . implode(', ', self::KINDS));
        }
        if ($priority !== null && !in_array($priority, self::PRIORITIES, true)) {
            throw new \InvalidArgumentException('invalid priority; expected one of: ' . implode(', ', self::PRIORITIES));
        }
        if ($epoch !== null && $kind !== 'control') {
            throw new \InvalidArgumentException('epoch is only meaningful on control messages; pass kind=control too');
        }
        if ($sig !== null && ($sig === '' || base64_decode($sig, true) === false)) {
            throw new \InvalidArgumentException('sig must be a non-empty base64 string');
        }
        if ($cert !== null) {
            if ($cert === '') {
                throw new \InvalidArgumentException('cert must be a non-empty JSON object string');
            }
            $decodedCert = json_decode($cert, true);
            if (!is_array($decodedCert) || array_is_list($decodedCert)) {
                throw new \InvalidArgumentException('cert must be a JSON object string (docs/IDENTITY.md 6.1)');
            }
        }
        if ($expires !== null) {
            $expiresTs = strtotime($expires);
            if ($expiresTs === false) { throw new \InvalidArgumentException("unparseable expires '{$expires}' (ISO 8601 expected)"); }
            if ($expiresTs <= time()) { throw new \InvalidArgumentException("expires '{$expires}' is in the past"); }
        }
        if ($task !== null) {
            $record = $this->storage->loadTask($task)
                ?? throw new \RuntimeException("task {$task} not found");
            $bound = $record['session'] ?? null;
            if (($record['owner'] ?? null) === $to) {
                if ($bound === null || $bound === '') {
                    throw new \RuntimeException("task {$task} has no bound session; send untargeted or pass an explicit session");
                }
                if ($session !== null && $session !== $bound) {
                    throw new \InvalidArgumentException("task {$task} is bound to session {$bound}; conflicting session '{$session}'");
                }
                $session = $bound;
            }
        }
        if ($preemption !== null && ($session === null || $session === '')) {
            throw new \InvalidArgumentException('preemption requires an explicit session or a task bound to one');
        }
        if ($session !== null) {
            // Recipients with no published session presence stay exempt
            // (first-use and legacy flows may register the session later).
            $held = $this->storage->agentDirectory()[$to]['sessions'] ?? [];
            if ($held !== [] && !isset($held[$session])) {
                throw new \InvalidArgumentException("session '{$session}' is not held by '{$to}'; it would be unreachable in their inbox");
            }
        }
        $providedId = $id;
        $id = $providedId ?? self::newMessageId();
        $envelope = ['id' => $id, 'from' => CONTINUUM_AGENT, 'body' => $body, 'ts' => $ts ?? gmdate('c')];
        foreach (['topic' => $topic, 'session' => $session, 'task' => $task, 'kind' => $kind,
                  'priority' => $priority, 'expires' => $expires, 'replyTo' => $replyTo, 'epoch' => $epoch,
                  'sig' => $sig, 'cert' => $cert, 'preemption' => $preemption] as $k => $v) {
            if ($v !== null) { $envelope[$k] = $v; }
        }
        if ($this->attestor !== null) {
            $envelope['attest'] = $this->attestor->attest(['to' => $to] + $envelope, CONTINUUM_AGENT);
        }
        // The durable inbox is written before any real-time path. XMPP is
        // primary when configured; direct injection is supplemental.
        if ($this->xmppBridge !== null && ($this->xmppDomain === null || $this->xmppDomain === '')) {
            throw new \RuntimeException('xmpp bridge configured without a domain');
        }
        if ($this->xmppBridge !== null) {
            $inbox = $this->storage->inboxPushOnce($to, $envelope);
            if (!$inbox['inserted']) { return $this->duplicateResult($to, $envelope, $preemption, $inbox); }
            return $this->sendViaXmpp($to, $envelope, $preemption, $inbox);
        }
        if ($preemption !== null) {
            $inbox = $this->storage->inboxPushOnce($to, $envelope);
            if (!$inbox['inserted']) { return $this->duplicateResult($to, $envelope, $preemption, $inbox); }
            $attempt = $this->injection?->deliver($to, $session, $envelope, $preemption) ?? [
                'pushed' => false, 'transport' => null, 'liveness' => null,
                'reason' => 'no direct transport configured', 'preemption_via' => 'none',
            ];
            return $this->sendWithInjection($to, $envelope, $preemption, $attempt, $inbox);
        }
        if ($providedId !== null) {
            $inbox = $this->storage->inboxPushOnce($to, $envelope);
            if (!$inbox['inserted']) { return $this->duplicateResult($to, $envelope, $preemption, $inbox); }
            $depth = $inbox['depth'];
        } else {
            $depth = $this->storage->inboxPush($to, $envelope);
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_send', [
            'id' => $id, 'to' => $to, 'topic' => $topic, 'session' => $session, 'task' => $task,
            'kind' => $kind, 'priority' => $priority, 'expires' => $expires, 'length' => strlen($body),
        ]);
        $data = ['id' => $id, 'to' => $to, 'queued' => true, 'depth' => $depth];
        $target = $session !== null ? ", session {$session}" : '';
        return ToolResult::structured("Message {$id} queued to '{$to}'{$target} (inbox depth {$depth}).", $data);
    }

    #[McpTool(
        name: 'message_inbox_pull',
        description: 'Pull and remove up to limit (default 20) messages from your inbox, oldest first. Only untargeted messages: session-addressed or currently leased messages are left for message_lease, and expired messages are dropped.',
        destructiveHint: true,
        idempotentHint: false,
        outputSchema: self::PULL_SCHEMA
    )]
    public function message_inbox_pull(int $limit = 20): ToolResult {
        if ($limit < 1) { $limit = 1; }
        $messages = $this->storage->inboxPull(CONTINUUM_AGENT, $limit);
        $data = ['agent' => CONTINUUM_AGENT, 'count' => count($messages), 'messages' => $messages];

        $lines = ['# Inbox for ' . CONTINUUM_AGENT . ' (' . count($messages) . ')'];
        if ($messages === []) {
            $lines[] = '(empty)';
        } else {
            foreach ($messages as $m) { $lines = array_merge($lines, self::renderMessage($m)); }
        }
        return ToolResult::structured(implode("\n", $lines), $data);
    }

    #[McpTool(
        name: 'message_lease',
        description: 'Lease up to limit (default 20) inbox messages without deleting them: the ones addressed to your session plus untargeted ones. Returns a lease id and expiry; when the lease expires the messages become eligible again, so acknowledge processed messages with message_ack. untargeted=false leases only messages addressed to your declared session.',
        idempotentHint: false,
        outputSchema: self::LEASE_SCHEMA
    )]
    public function message_lease(int $limit = 20, int $ttlSeconds = 60, bool $untargeted = true): ToolResult {
        if ($limit < 1) { $limit = 1; }
        if ($ttlSeconds < 1 || $ttlSeconds > 3600) {
            throw new \InvalidArgumentException('ttlSeconds must be between 1 and 3600');
        }
        $session = SessionContext::session();
        if (!$untargeted && $session === null) {
            throw new \InvalidArgumentException('untargeted=false requires a declared session (params._meta.session)');
        }
        $lease = $this->storage->inboxLease(CONTINUUM_AGENT, $session, $limit, $ttlSeconds, $untargeted);
        $messages = $lease['messages'];
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_lease', [
            'lease' => $lease['id'], 'session' => $session, 'count' => count($messages), 'ttl' => $ttlSeconds,
        ]);
        $data = [
            'agent' => CONTINUUM_AGENT,
            'lease' => $lease['id'],
            'expires_at' => $lease['expires_at'],
            'count' => count($messages),
            'messages' => $messages,
        ];
        $scope = $session !== null ? " for session {$session}" : ' (untargeted)';
        $lines = ['# Leased ' . count($messages) . ' message(s)' . $scope
            . "; lease {$lease['id']}, until {$lease['expires_at']}"];
        if ($messages === []) {
            $lines[] = '(empty)';
        } else {
            foreach ($messages as $m) { $lines = array_merge($lines, self::renderMessage($m)); }
        }
        return ToolResult::structured(implode("\n", $lines), $data);
    }

    #[McpTool(
        name: 'message_ack',
        description: 'Acknowledge leased messages by id, deleting them from your inbox. Message ids make processing idempotent: unknown ids are reported, not an error.',
        idempotentHint: true,
        outputSchema: self::ACK_SCHEMA
    )]
    public function message_ack(array $ids): ToolResult {
        $ids = array_values(array_filter($ids, fn($id) => is_string($id) && $id !== ''));
        if ($ids === []) { throw new \InvalidArgumentException('ids must be a non-empty list of message ids'); }
        $acked = $this->storage->inboxAck(CONTINUUM_AGENT, $ids);
        $unknown = array_values(array_diff($ids, $acked));
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_ack', ['acked' => count($acked), 'ids' => $acked, 'unknown' => $unknown]);
        $data = ['agent' => CONTINUUM_AGENT, 'acked' => $acked, 'unknown' => $unknown, 'count' => count($acked)];
        $text = 'Acknowledged ' . count($acked) . ' message(s) for ' . CONTINUUM_AGENT
            . ($unknown !== [] ? '; unknown: ' . implode(', ', $unknown) : '') . '.';
        return ToolResult::structured($text, $data);
    }

    #[McpTool(
        name: 'message_broadcast',
        description: 'Send a message to every other registered agent, optionally with a topic.',
        outputSchema: self::BROADCAST_SCHEMA
    )]
    public function message_broadcast(string $body, ?string $topic = null): ToolResult {
        if ($this->xmppBridge !== null && ($this->xmppDomain === null || $this->xmppDomain === '')) {
            throw new \RuntimeException('xmpp bridge configured without a domain');
        }
        $delivered = [];
        $failed = [];
        foreach ($this->storage->agents() as $agentId) {
            if ($agentId === CONTINUUM_AGENT) { continue; }
            if ($this->xmppBridge !== null) {
                try {
                    $envelope = [
                        'id' => self::newMessageId(), 'from' => CONTINUUM_AGENT,
                        'topic' => $topic, 'body' => $body, 'ts' => gmdate('c'), 'kind' => 'notice',
                    ];
                    $this->storage->inboxPushOnce($agentId, $envelope);
                    $this->xmppBridge->send(CONTINUUM_AGENT, "{$agentId}@{$this->xmppDomain}", $body, [
                        'id' => $envelope['id'],
                        'kind' => 'notice',
                        ...($topic !== null ? ['topic' => $topic] : []),
                    ]);
                    $delivered[] = $agentId;
                } catch (\RuntimeException) {
                    $failed[] = $agentId;
                }
                continue;
            }
            $this->storage->inboxPush($agentId, [
                'id' => self::newMessageId(),
                'from' => CONTINUUM_AGENT,
                'topic' => $topic,
                'body' => $body,
                'ts' => gmdate('c'),
            ]);
            $delivered[] = $agentId;
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_broadcast', [
            'topic' => $topic, 'recipients' => count($delivered),
            'failed' => $failed, 'via' => $this->xmppBridge !== null ? 'xmpp-bridge' : 'local',
        ]);
        $data = ['delivered' => $delivered, 'count' => count($delivered)];
        $text = $delivered === []
            ? 'Broadcast dropped: no other agents registered.'
            : 'Broadcast to ' . count($delivered) . ' agent(s): ' . implode(', ', $delivered) . '.';
        if ($failed !== []) {
            $text .= ' Failed for: ' . implode(', ', $failed) . '.';
            $data['failed'] = $failed;
        }
        return ToolResult::structured($text, $data);
    }

    /** Return an idempotent result without repeating any real-time delivery. */
    private function duplicateResult(string $to, array $envelope, ?string $preemption, array $inbox): ToolResult {
        $id = $envelope['id'];
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_send_duplicate', [
            'id' => $id, 'to' => $to, 'session' => $envelope['session'] ?? null,
            'preemption' => $preemption, 'via' => 'inbox',
        ]);
        $data = [
            'id' => $id, 'to' => $to, 'queued' => true, 'depth' => (int)$inbox['depth'],
            'via' => 'inbox', 'duplicate' => true,
        ];
        if ($preemption !== null) {
            $data += ['pushed' => false, 'preemption' => $preemption, 'preemption_via' => 'none'];
        }
        return ToolResult::structured(
            "Message {$id} was already recorded for '{$to}' (inbox depth {$inbox['depth']}); no duplicate delivery was attempted.",
            $data
        );
    }

    /** Complete a no-XMPP send after the durable write and direct attempt. */
    private function sendWithInjection(string $to, array $envelope, string $preemption, array $attempt, array $inbox): ToolResult {
        $id = $envelope['id'];
        $pushed = (bool)$attempt['pushed'];
        $log = [
            'id' => $id, 'to' => $to, 'topic' => $envelope['topic'] ?? null,
            'session' => $envelope['session'] ?? null, 'task' => $envelope['task'] ?? null,
            'kind' => $envelope['kind'] ?? null, 'priority' => $envelope['priority'] ?? null,
            'expires' => $envelope['expires'] ?? null, 'length' => strlen($envelope['body']),
            'via' => 'inbox', 'pushed' => $pushed, 'preemption' => $preemption,
            'preemption_via' => $attempt['preemption_via'], 'liveness' => $attempt['liveness'],
        ];
        $data = [
            'id' => $id, 'to' => $to, 'queued' => true, 'depth' => (int)$inbox['depth'], 'via' => 'inbox',
            'pushed' => $pushed, 'preemption' => $preemption,
            'preemption_via' => $attempt['preemption_via'], 'liveness' => $attempt['liveness'],
        ];
        if (array_key_exists('interrupted', $attempt)) {
            $log['interrupted'] = $data['interrupted'] = (bool)$attempt['interrupted'];
        }
        $text = "Message {$id} queued to '{$to}', session {$envelope['session']} (inbox depth {$inbox['depth']}).";
        if ($pushed) {
            $log['injection_via'] = $data['injection_via'] = $attempt['transport'];
            $ack = $attempt['ack'] ?? [];
            if (isset($ack['http_status'])) {
                $log['remote_status'] = $data['remote_status'] = (int)$ack['http_status'];
            }
            if (array_key_exists('resumed', $ack)) {
                $log['resumed'] = $data['resumed'] = (bool)$ack['resumed'];
            }
            $text .= " Supplemental direct injection delivered via {$attempt['transport']} ({$attempt['liveness']}, {$preemption}).";
            if (($ack['resumed'] ?? true) === false) {
                $text .= ' The remote saved the message but could not resume the session.';
            }
        } else {
            $log['fallback_reason'] = $data['fallback_reason'] = $attempt['reason'];
            $text .= " Direct injection was not delivered: {$attempt['reason']}.";
            if (($attempt['interrupted'] ?? false) === true) {
                $text .= ' The remote session was interrupted before the failure.';
            }
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_send', $log);
        return ToolResult::structured($text, $data);
    }

    /**
     * Send through the bridge sidecar (issue #3). The durable inbox copy is
     * already written by the caller; XMPP adds real-time fan-out and the
     * inbound hook deduplicates by envelope id. Direct injection, when asked,
     * is supplemental and shares that same id. XMPP failures fail the call.
     */
    private function sendViaXmpp(string $to, array $envelope, ?string $preemption, array $inbox): ToolResult {
        $domain = $this->xmppDomain;
        if ($domain === null || $domain === '') {
            throw new \RuntimeException('xmpp bridge configured without a domain');
        }
        $jid = "{$to}@{$domain}";
        $fields = [];
        foreach (['kind', 'session', 'task', 'expires', 'priority', 'epoch', 'sig', 'cert', 'attest', 'preemption'] as $f) {
            if (isset($envelope[$f])) { $fields[$f] = $envelope[$f]; }
        }
        if (isset($envelope['replyTo'])) { $fields['thread'] = $envelope['replyTo']; }
        $fields['id'] = $envelope['id'];
        $fields['topic'] = $envelope['topic'] ?? null;
        $log = [
            'id' => $envelope['id'], 'to' => $to, 'jid' => $jid, 'via' => 'xmpp-bridge',
            'session' => $envelope['session'] ?? null, 'task' => $envelope['task'] ?? null,
            'kind' => $envelope['kind'] ?? null, 'priority' => $envelope['priority'] ?? null,
            'expires' => $envelope['expires'] ?? null, 'length' => strlen($envelope['body']),
            'inbox_depth' => (int)$inbox['depth'],
        ];
        try {
            $answer = $this->xmppBridge->send(CONTINUUM_AGENT, $jid, $envelope['body'], array_filter($fields));
        } catch (\Throwable $e) {
            $log['error'] = 'xmpp delivery failed';
            $this->storage->appendLog(CONTINUUM_AGENT, 'message_send', $log);
            throw $e;
        }
        $stanzaId = is_string($answer['id'] ?? null) ? $answer['id'] : $envelope['id'];
        $log['stanza_id'] = $stanzaId;
        $data = ['id' => $stanzaId, 'to' => $to, 'queued' => true, 'depth' => (int)$inbox['depth'], 'via' => 'xmpp-bridge'];
        $text = "Message {$stanzaId} sent over XMPP to {$jid} (inbox depth {$inbox['depth']}).";
        if ($preemption !== null) {
            $attempt = $this->injection?->deliver($to, $envelope['session'], $envelope, $preemption) ?? [
                'pushed' => false, 'transport' => null, 'liveness' => null,
                'reason' => 'no direct transport configured', 'preemption_via' => 'none',
            ];
            $pushed = (bool)$attempt['pushed'];
            $log += ['pushed' => $pushed, 'preemption' => $preemption,
                'preemption_via' => $attempt['preemption_via'], 'liveness' => $attempt['liveness']];
            $data += ['pushed' => $pushed, 'preemption' => $preemption,
                'preemption_via' => $attempt['preemption_via'], 'liveness' => $attempt['liveness']];
            if (array_key_exists('interrupted', $attempt)) {
                $log['interrupted'] = $data['interrupted'] = (bool)$attempt['interrupted'];
            }
            if ($pushed) {
                $log['injection_via'] = $data['injection_via'] = $attempt['transport'];
                $ack = $attempt['ack'] ?? [];
                if (isset($ack['http_status'])) {
                    $log['remote_status'] = $data['remote_status'] = (int)$ack['http_status'];
                }
                if (array_key_exists('resumed', $ack)) {
                    $log['resumed'] = $data['resumed'] = (bool)$ack['resumed'];
                }
                $text .= " Supplemental direct injection delivered via {$attempt['transport']} ({$attempt['liveness']}, {$preemption}).";
                if (($ack['resumed'] ?? true) === false) {
                    $text .= ' The remote saved the message but could not resume the session.';
                }
            } else {
                $log['injection_reason'] = $data['injection_reason'] = $attempt['reason'];
                $text .= " Supplemental direct injection was not delivered: {$attempt['reason']}. XMPP delivery succeeded.";
                if (($attempt['interrupted'] ?? false) === true) {
                    $text .= ' The remote session was interrupted before the failure.';
                }
            }
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_send', $log);
        return ToolResult::structured($text, $data);
    }

    private static function newMessageId(): string {
        return 'M-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /** One message as markdown lines: header + continuation lines. */
    private static function renderMessage(array $m): array {
        $meta = [];
        foreach (['id', 'kind', 'priority', 'preemption', 'session', 'task', 'replyTo', 'epoch'] as $k) {
            if (!empty($m[$k])) { $meta[] = $k === 'id' ? $m[$k] : "{$k}={$m[$k]}"; }
        }
        $lines = ['- ' . ($m['ts'] ?? '?') . ' from ' . ($m['from'] ?? '?')
            . (!empty($m['topic']) ? ' [' . $m['topic'] . ']' : '')
            . ($meta !== [] ? ' (' . implode(', ', $meta) . ')' : '') . ':'];
        foreach (explode("\n", (string)($m['body'] ?? '')) as $bodyLine) {
            $lines[] = '  ' . $bodyLine;
        }
        return $lines;
    }

    private const MESSAGE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'from' => ['type' => 'string'],
            'topic' => ['type' => ['string', 'null']],
            'body' => ['type' => 'string'],
            'ts' => ['type' => 'string'],
            'session' => ['type' => 'string'],
            'task' => ['type' => 'string'],
            'kind' => ['type' => 'string', 'enum' => ['notice', 'context', 'directive', 'control']],
            'priority' => ['type' => 'string', 'enum' => ['normal', 'urgent']],
            'preemption' => ['type' => 'string', 'enum' => ['wakeup', 'steer', 'interrupt']],
            'expires' => ['type' => 'string'],
            'replyTo' => ['type' => 'string'],
            'epoch' => ['type' => 'string'],
            'sig' => ['type' => 'string'],
            'cert' => ['type' => 'string'],
            'attest' => ['type' => 'string'],
            'lease' => ['type' => 'string'],
            'lease_until' => ['type' => 'integer'],
        ],
    ];

    private const SEND_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'to' => ['type' => 'string'],
            'queued' => ['type' => 'boolean'],
            'depth' => ['type' => 'integer'],
            'via' => ['type' => 'string'],
            'pushed' => ['type' => 'boolean'],
            'preemption' => ['type' => 'string', 'enum' => ['wakeup', 'steer', 'interrupt']],
            'preemption_via' => ['type' => 'string'],
            'liveness' => ['type' => ['string', 'null'], 'enum' => ['running', 'idle', 'unreachable', null]],
            'interrupted' => ['type' => 'boolean'],
            'fallback_reason' => ['type' => ['string', 'null']],
            'injection_via' => ['type' => 'string'],
            'injection_reason' => ['type' => ['string', 'null']],
            'remote_status' => ['type' => 'integer'],
            'resumed' => ['type' => 'boolean'],
            'duplicate' => ['type' => 'boolean'],
        ],
        'required' => ['id', 'to', 'queued', 'depth'],
    ];

    private const PULL_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'messages' => ['type' => 'array', 'items' => self::MESSAGE_SCHEMA],
        ],
        'required' => ['agent', 'count', 'messages'],
    ];

    private const LEASE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string'],
            'lease' => ['type' => 'string'],
            'expires_at' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'messages' => ['type' => 'array', 'items' => self::MESSAGE_SCHEMA],
        ],
        'required' => ['agent', 'lease', 'expires_at', 'count', 'messages'],
    ];

    private const ACK_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string'],
            'acked' => ['type' => 'array', 'items' => ['type' => 'string']],
            'unknown' => ['type' => 'array', 'items' => ['type' => 'string']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['agent', 'acked', 'unknown', 'count'],
    ];

    private const BROADCAST_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'delivered' => ['type' => 'array', 'items' => ['type' => 'string']],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['delivered', 'count'],
    ];
}
