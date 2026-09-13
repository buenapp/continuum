<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use Continuum\Storage\ContinuumStorage;

/**
 * board_status: the one-call live picture — registered agents with last
 * seen, open tasks with owners, held advisory locks, board key counts per
 * scope, queue depths, and the most recent events.
 */
class StatusTools {

    public function __construct(private ContinuumStorage $storage) {}

    #[McpTool(
        name: 'board_status',
        description: 'Whole-board snapshot: agents with last-seen, open tasks with owners, held advisory locks, board scopes, queue depths, recent events. The pane of glass.',
        readOnlyHint: true
    )]
    public function board_status(): array {
        $now = time();

        $agents = [];
        foreach ($this->storage->agentDirectory() as $agentId => $record) {
            $ts = isset($record['heartbeat']) ? (int)$record['heartbeat'] : null;
            $agents[] = [
                'agent' => $agentId,
                'label' => $record['label'] ?? null,
                'capabilities' => isset($record['capabilities']) ? json_decode($record['capabilities'], true) : null,
                'working_on' => $record['working_on'] ?? null,
                'last_seen_s_ago' => $ts === null ? null : $now - $ts,
                'registered_at' => $record['registered_at'] ?? null,
            ];
        }
        usort($agents, fn($a, $b) => strcmp($a['agent'], $b['agent']));

        $openTasks = [];
        $scopes = [];
        foreach ($this->storage->listTaskDocs() as $id => $doc) {
            $scopes[] = $doc['scope'] ?? 'global';
            $status = $doc['status'] ?? 'pending';
            if ($status === 'done' || $status === 'cancelled') { continue; }
            $openTasks[] = [
                'task' => $id,
                'title' => $doc['title'] ?? '',
                'scope' => $doc['scope'] ?? 'global',
                'status' => $status,
                'owner' => $doc['owner'] ?? null,
                'priority' => $doc['priority'] ?? 2,
            ];
        }
        usort($openTasks, fn($a, $b) => ($a['priority'] <=> $b['priority']) ?: strcmp($a['task'], $b['task']));

        $queues = [];
        foreach (array_unique($scopes) as $scope) {
            $queues[$scope] = $this->storage->queueDepth($scope);
        }

        $boards = [];
        foreach ($this->storage->listBoardDocsAll() as $id => $_doc) {
            $scope = explode('/', $id, 2)[0];
            $boards[$scope] = ($boards[$scope] ?? 0) + 1;
        }

        return [
            'now' => gmdate('c'),
            'agents' => $agents,
            'open_tasks' => $openTasks,
            'locks' => $this->storage->listLocks(),
            'boards' => $boards,
            'queues' => $queues,
            'recent_events' => (new EventTools($this->storage))->event_log(null, 10)['events'],
        ];
    }
}
