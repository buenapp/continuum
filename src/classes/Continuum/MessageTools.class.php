<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
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
        description: 'Send a message to another agent\'s inbox, optionally with a topic.',
        outputSchema: self::SEND_SCHEMA
    )]
    public function message_send(string $to, string $body, ?string $topic = null): ToolResult {
        if ($to === '') { throw new \RuntimeException('recipient must not be empty'); }
        $depth = $this->storage->inboxPush($to, [
            'from' => CONTINUUM_AGENT,
            'topic' => $topic,
            'body' => $body,
            'ts' => gmdate('c'),
        ]);
        $this->storage->appendLog(CONTINUUM_AGENT, 'message_send', ['to' => $to, 'topic' => $topic, 'length' => strlen($body)]);
        $data = ['to' => $to, 'queued' => true, 'depth' => $depth];
        return ToolResult::structured("Message queued to '{$to}' (inbox depth {$depth}).", $data);
    }

    #[McpTool(
        name: 'message_inbox_pull',
        description: 'Pull and remove up to limit (default 20) messages from your inbox, oldest first.',
        destructiveHint: true,
        idempotentHint: false,
        outputSchema: self::PULL_SCHEMA
    )]
    public function message_inbox_pull(int $limit = 20): ToolResult {
        if ($limit < 1) { $limit = 1; }
        $messages = $this->storage->inboxPull(CONTINUUM_AGENT, $limit);
        $data = ['agent' => CONTINUUM_AGENT, 'count' => count($messages), 'messages' => $messages];

        $lines = ['# Inbox for ' . CONTINUUM_AGENT . ' (' . count($messages) . ')'];
        foreach ($messages as $m) {
            $lines[] = '- ' . ($m['ts'] ?? '?') . ' from ' . ($m['from'] ?? '?')
                . (!empty($m['topic']) ? ' [' . $m['topic'] . ']' : '') . ':';
            foreach (explode("\n", (string)($m['body'] ?? '')) as $bodyLine) {
                $lines[] = '  ' . $bodyLine;
            }
        }
        if ($messages === []) { $lines[] = '(empty)'; }
        return ToolResult::structured(implode("\n", $lines), $data);
    }

    #[McpTool(
        name: 'message_broadcast',
        description: 'Send a message to every other registered agent, optionally with a topic.',
        outputSchema: self::BROADCAST_SCHEMA
    )]
    public function message_broadcast(string $body, ?string $topic = null): ToolResult {
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
        $data = ['delivered' => $delivered, 'count' => count($delivered)];
        $text = $delivered === []
            ? 'Broadcast dropped: no other agents registered.'
            : 'Broadcast to ' . count($delivered) . ' agent(s): ' . implode(', ', $delivered) . '.';
        return ToolResult::structured($text, $data);
    }

    private const SEND_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'to' => ['type' => 'string'],
            'queued' => ['type' => 'boolean'],
            'depth' => ['type' => 'integer'],
        ],
        'required' => ['to', 'queued', 'depth'],
    ];

    private const PULL_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'agent' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'messages' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string'],
                    'topic' => ['type' => ['string', 'null']],
                    'body' => ['type' => 'string'],
                    'ts' => ['type' => 'string'],
                ],
            ]],
        ],
        'required' => ['agent', 'count', 'messages'],
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
