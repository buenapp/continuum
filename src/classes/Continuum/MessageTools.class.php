<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use Continuum\Storage\ContinuumStorage;
use Continuum\Bridge\XmppBridgeInterface;

/**
 * Agent-to-agent messaging on per-agent inboxes (ValKey lists).
 *
 * Pull model: recipients fetch their own inbox; nothing is pushed to a
 * client. Sends, leases and acknowledgements are logged; pulls are not
 * (assumed read-mostly churn). Messages may carry targeting fields:
 *   session  - only a reader on that session (or message_lease scoped
 *              to it) receives it
 *   task     - resolved at send time to the session bound to the task
 *   kind     - notice | context | directive | control
 *   priority - normal | urgent
 *   expires  - ISO-8601; expired messages are dropped from reads
 *   replyTo  - id of the message being answered
 *   epoch    - control traffic only: the master epoch the sender acts
 *              under, so receivers drop stale-epoch directives
 *
 * message_lease/message_ack are the non-destructive read path: a lease
 * marks messages in place until it expires, then they become eligible
 * again. The legacy message_inbox_pull still deletes on read, but only
 * ever touches untargeted, unleased, unexpired messages.
 */
class MessageTools {

    private const KINDS = ['notice', 'context', 'directive', 'control'];
    private const PRIORITIES = ['normal', 'urgent'];

    public function __construct(
        private ContinuumStorage $storage,
        private ?XmppBridgeInterface $xmppBridge = null,
        private ?string $xmppDomain = null,
    ) {}

    #[McpTool(
        name: 'message_send',
        description: 'Send a message to another agent\'s inbox. Optional targeting: session (delivered only to that session), task (resolves to the session bound to the task), kind (notice|context|directive|control), priority (normal|urgent), expires (ISO 8601), replyTo (message id), epoch (master epoch; control kind only).',
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
    ): ToolResult {
        if ($to === '') { throw new \RuntimeException('recipient must not be empty'); }
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('invalid kind; expected one of: ' . implode(', ', self::KINDS));
        }
        if ($priority !== null && !in_array($priority, self::PRIORITIES, true)) {
            throw new \InvalidArgumentException('invalid priority; expected one of: ' . implode(', ', self::PRIORITIES));
        }
        if ($epoch !== null && $kind !== 'control') {
            throw new \InvalidArgumentException('epoch is only meaningful on control messages; pass kind=control too');
        }
        if ($expires !== null) {
            $ts = strtotime($expires);
            if ($ts === false) { throw new \InvalidArgumentException("unparseable expires '{$expires}' (ISO 8601 expected)"); }
            if ($ts <= time()) { throw new \InvalidArgumentException("expires '{$expires}' is in the past"); }
        }
        if ($task !== null) {
            $bound = $this->resolveTaskSession($task);
            if ($session !== null && $session !== $bound) {
                throw new \InvalidArgumentException("task {$task} is bound to session {$bound}; conflicting session '{$session}'");
            }
            $session = $bound;
        }
        $id = self::newMessageId();
        $envelope = ['id' => $id, 'from' => CONTINUUM_AGENT, 'body' => $body, 'ts' => gmdate('c')];
        foreach (['topic' => $topic, 'session' => $session, 'task' => $task, 'kind' => $kind,
                  'priority' => $priority, 'expires' => $expires, 'replyTo' => $replyTo, 'epoch' => $epoch] as $k => $v) {
            if ($v !== null) { $envelope[$k] = $v; }
        }
        if ($this->xmppBridge !== null) {
            return $this->sendViaXmpp($to, $envelope);
        }
        $depth = $this->storage->inboxPush($to, $envelope);
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
        description: 'Lease up to limit (default 20) inbox messages without deleting them: the ones addressed to your session plus untargeted ones. Returns a lease id and expiry; when the lease expires the messages become eligible again, so acknowledge processed messages with message_ack.',
        idempotentHint: false,
        outputSchema: self::LEASE_SCHEMA
    )]
    public function message_lease(int $limit = 20, int $ttlSeconds = 60): ToolResult {
        if ($limit < 1) { $limit = 1; }
        if ($ttlSeconds < 1 || $ttlSeconds > 3600) {
            throw new \InvalidArgumentException('ttlSeconds must be between 1 and 3600');
        }
        $session = SessionContext::session();
        $lease = $this->storage->inboxLease(CONTINUUM_AGENT, $session, $limit, $ttlSeconds);
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
        $delivered = [];
        $failed = [];
        foreach ($this->storage->agents() as $agentId) {
            if ($agentId === CONTINUUM_AGENT) { continue; }
            if ($this->xmppBridge !== null) {
                try {
                    $this->xmppBridge->send(CONTINUUM_AGENT, "{$agentId}@{$this->xmppDomain}", $body, [
                        'id' => self::newMessageId(),
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

    /**
     * Send through the bridge sidecar (issue #3): the target is the
     * agent's account, always addressed as the bare JID (`to@domain`);
     * a session target rides in the sonya payload instead of becoming
     * a JID resource (bridge-held identities own a single stream, so a
     * session-named resource would not exist and the stanza would be
     * lost). Local inbox delivery does not happen here; the recipient's
     * copy arrives through the bridge's inbound hook
     * (message_xmpp_inbound), so there is no double delivery on this
     * path. Transport failures fail the call: silently falling back to
     * a local-only inbox would change the operator's delivery contract.
     */
    private function sendViaXmpp(string $to, array $envelope): ToolResult {
        $domain = $this->xmppDomain;
        if ($domain === null || $domain === '') {
            throw new \RuntimeException('xmpp bridge configured without a domain');
        }
        $jid = "{$to}@{$domain}";
        $fields = [];
        foreach (['kind', 'session', 'task', 'expires', 'priority', 'epoch'] as $f) {
            if (isset($envelope[$f])) { $fields[$f] = $envelope[$f]; }
        }
        if (isset($envelope['replyTo'])) { $fields['thread'] = $envelope['replyTo']; }
        $fields['id'] = $envelope['id'];
        $fields['topic'] = $envelope['topic'] ?? null;
        $answer = $this->xmppBridge->send(CONTINUUM_AGENT, $jid, $envelope['body'], array_filter($fields));
        $stanzaId = is_string($answer['id'] ?? null) ? $answer['id'] : $envelope['id'];
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_send', [
            'id' => $envelope['id'], 'to' => $to, 'jid' => $jid, 'stanza_id' => $stanzaId, 'via' => 'xmpp-bridge',
            'session' => $envelope['session'] ?? null, 'task' => $envelope['task'] ?? null,
            'kind' => $envelope['kind'] ?? null, 'priority' => $envelope['priority'] ?? null,
            'expires' => $envelope['expires'] ?? null, 'length' => strlen($envelope['body']),
        ]);
        $data = ['id' => $stanzaId, 'to' => $to, 'queued' => true, 'depth' => 0, 'via' => 'xmpp-bridge'];
        return ToolResult::structured("Message {$stanzaId} sent over XMPP to {$jid}.", $data);
    }

    /** A task target resolves to the session bound to it by its claim. */
    private function resolveTaskSession(string $taskId): string {        $task = $this->storage->loadTask($taskId)
            ?? throw new \RuntimeException("task {$taskId} not found");
        $session = $task['session'] ?? null;
        if ($session === null || $session === '') {
            throw new \RuntimeException("task {$taskId} has no bound session; send untargeted or pass an explicit session");
        }
        return $session;
    }

    private static function newMessageId(): string {
        return 'M-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /** One message as markdown lines: header + continuation lines. */
    private static function renderMessage(array $m): array {
        $meta = [];
        foreach (['id', 'kind', 'priority', 'session', 'task', 'replyTo', 'epoch'] as $k) {
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
            'expires' => ['type' => 'string'],
            'replyTo' => ['type' => 'string'],
            'epoch' => ['type' => 'string'],
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
