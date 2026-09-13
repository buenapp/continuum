<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use Continuum\Storage\ContinuumStorage;

/**
 * event_log: read the append-only audit trail. Every mutation writes one
 * event ({agent, type, data, ts}); this tool reads them back, newest first.
 */
class EventTools {

    public function __construct(private ContinuumStorage $storage) {}

    #[McpTool(
        name: 'coordination_event_log',
        renamedFrom: 'event_log',
        description: 'Read the coordination board\'s append-only event log (every task/board/lock/message mutation), newest first. Filter by event type (task_claim, blackboard_write, ...), scope, and/or an ISO-8601 `since` timestamp.',
        readOnlyHint: true,
        outputSchema: self::LOG_SCHEMA
    )]
    public function coordination_event_log(?string $since = null, int $limit = 50, ?string $type = null, ?string $scope = null): ToolResult {
        $events = [];
        foreach ($this->storage->listEventDocs() as $id => $doc) {
            if ($type !== null && ($doc['type'] ?? null) !== $type) { continue; }
            if ($scope !== null && ($doc['data']['scope'] ?? null) !== $scope) { continue; }
            if ($since !== null && ($doc['ts'] ?? '') < $since) { continue; }
            $doc['id'] = $id;
            $events[] = $doc;
        }
        usort($events, fn($a, $b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
        $data = ['count' => min(count($events), $limit), 'total' => count($events), 'events' => array_slice($events, 0, $limit)];

        $lines = ["# Coordination events ({$data['count']} of {$data['total']}, newest first)"];
        foreach ($data['events'] as $e) {
            $bits = [];
            foreach (($e['data'] ?? []) as $k => $v) {
                if (is_scalar($v) && $v !== '') { $bits[] = "{$k}={$v}"; }
            }
            $lines[] = '- ' . ($e['ts'] ?? '?') . ' ' . ($e['agent'] ?? '?') . ' ' . ($e['type'] ?? '?')
                . ($bits ? ' — ' . implode(' ', $bits) : '');
        }
        if ($data['events'] === []) { $lines[] = '(none)'; }
        return ToolResult::structured(implode("\n", $lines), $data);
    }

    private const LOG_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'events' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
        'required' => ['count', 'total', 'events'],
    ];
}
