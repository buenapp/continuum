<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use Continuum\Storage\ContinuumStorage;

/**
 * Agent-to-agent messaging on per-agent inboxes (ValKey lists).
 *
 * Pull model: recipients fetch their own inbox; nothing is pushed to a
 * client. Sends and broadcasts are logged; pulls are not.
 */
class MessageTools {

    public function __construct(private ContinuumStorage $storage) {}

    #[McpTool(
        name: 'message_send',
        description: 'Send a message to another registered agent\'s inbox, optionally under a topic.'
    )]
    public function message_send(string $to, string $body, ?string $topic = null): array {
        if ($to === '') { throw new \RuntimeException('recipient must not be empty'); }
        $depth = $this->storage->inboxPush($to, [
            'from' => CONTINUUM_AGENT,
            'topic' => $topic,
            'body' => $body,
            'ts' => gmdate('c'),
        ]);
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_send', ['to' => $to, 'topic' => $topic, 'length' => strlen($body)]);
        return ['to' => $to, 'queued' => true, 'depth' => $depth];
    }

    #[McpTool(
        name: 'message_inbox_pull',
        description: 'Pull up to `limit` (default 20) messages from your own inbox, oldest first. Pulled messages are removed.',
        destructiveHint: true,
        idempotentHint: false
    )]
    public function message_inbox_pull(int $limit = 20): array {
        if ($limit < 1) { $limit = 1; }
        $messages = $this->storage->inboxPull(CONTINUUM_AGENT, $limit);
        return ['agent' => CONTINUUM_AGENT, 'count' => count($messages), 'messages' => $messages];
    }

    #[McpTool(
        name: 'message_broadcast',
        description: 'Send a message to every registered agent except yourself, optionally under a topic.'
    )]
    public function message_broadcast(string $body, ?string $topic = null): array {
        $delivered = [];
        foreach ($this->storage->agents() as $agentId) {
            if ($agentId === CONTINUUM_AGENT) { continue; }
            $this->storage->inboxPush($agentId, [
                'from' => CONTINUUM_AGENT,
                'topic' => $topic,
                'body' => $body,
                'ts' => gmdate('c'),
            ]);
            $delivered[] = $agentId;
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_broadcast', ['topic' => $topic, 'recipients' => count($delivered)]);
        return ['delivered' => $delivered, 'count' => count($delivered)];
    }
}
