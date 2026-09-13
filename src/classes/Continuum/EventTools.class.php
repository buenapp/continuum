<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
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
        readOnlyHint: true
    )]
    public function coordination_event_log(?string $since = null, int $limit = 50, ?string $type = null, ?string $scope = null): array {
        $events = [];
        foreach ($this->storage->listEventDocs() as $id => $doc) {
            if ($type !== null && ($doc['type'] ?? null) !== $type) { continue; }
            if ($scope !== null && ($doc['data']['scope'] ?? null) !== $scope) { continue; }
            if ($since !== null && ($doc['ts'] ?? '') < $since) { continue; }
            $doc['id'] = $id;
            $events[] = $doc;
        }
        usort($events, fn($a, $b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
        return ['count' => min(count($events), $limit), 'total' => count($events), 'events' => array_slice($events, 0, $limit)];
    }
}
