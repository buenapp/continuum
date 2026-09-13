<?php

namespace Continuum;

use EnchiladaMCP\McpResource;
use Continuum\Storage\ContinuumStorage;

/**
 * Event log resources: the append-only coordination audit trail as reads.
 *
 * The tail mirrors the coordination_event_log tool default (50, newest
 * first); the since/ template replays events at or after an ISO-8601
 * timestamp.
 */
class EventResources {

    public function __construct(private ContinuumStorage $storage) {}

    /**
     * Most recent 50 events, newest first.
     */
    #[McpResource(
        uriTemplate: 'continuum://events',
        description: 'Recent tail of the append-only event log (50 events, newest first).',
        annotations: ['audience' => ['assistant']]
    )]
    public function events_tail(): array {
        return $this->render($this->collect(null), 50);
    }

    /**
     * Events at or after an ISO-8601 timestamp (string compare: the log
     * stores gmdate('c') values, all UTC).
     */
    #[McpResource(
        uriTemplate: 'continuum://events/since/{timestamp}',
        description: 'Replay event log entries at or after the given ISO-8601 UTC timestamp.',
        annotations: ['audience' => ['assistant']]
    )]
    public function events_since(string $timestamp): array {
        return $this->render($this->collect($timestamp), 500);
    }

    private function collect(?string $since): array {
        $events = [];
        foreach ($this->storage->listEventDocs() as $id => $doc) {
            if ($since !== null && ($doc['ts'] ?? '') < $since) { continue; }
            $doc['id'] = $id;
            $events[] = $doc;
        }
        usort($events, fn($a, $b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
        return $events;
    }

    private function render(array $events, int $limit): array {
        $latest = $events[0]['ts'] ?? null;
        $annotations = ['audience' => ['assistant']];
        if (is_string($latest) && $latest !== '') {
            $annotations['lastModified'] = $latest;
        }
        return [
            'text' => json_encode([
                'count' => min(count($events), $limit),
                'total' => count($events),
                'events' => array_slice($events, 0, $limit),
            ], JSON_UNESCAPED_SLASHES),
            'annotations' => $annotations,
        ];
    }
}
