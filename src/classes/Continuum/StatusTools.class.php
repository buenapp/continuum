<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
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
        readOnlyHint: true,
        outputSchema: self::STATUS_SCHEMA
    )]
    public function board_status(): ToolResult {
        $now = time();

        $agents = [];
        foreach ($this->storage->agentDirectory() as $agentId => $record) {
            $ts = isset($record['heartbeat']) ? (int)$record['heartbeat'] : null;
            $sessions = [];
            foreach ((array)($record['sessions'] ?? []) as $sid => $srec) {
                $sTs = isset($srec['heartbeat']) ? (int)$srec['heartbeat'] : null;
                $sessions[] = [
                    'session' => $sid,
                    'working_on' => $srec['working_on'] ?? null,
                    'last_seen_s_ago' => $sTs === null ? null : $now - $sTs,
                ];
            }
            usort($sessions, fn($a, $b) => ($a['last_seen_s_ago'] ?? PHP_INT_MAX) <=> ($b['last_seen_s_ago'] ?? PHP_INT_MAX));
            $agents[] = [
                'agent' => $agentId,
                'label' => $record['label'] ?? null,
                'capabilities' => isset($record['capabilities']) ? json_decode($record['capabilities'], true) : null,
                'working_on' => $record['working_on'] ?? null,
                'sessions' => $sessions,
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
                'phorge_task_id' => $doc['phorge_task_id'] ?? null,
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

        $data = [
            'now' => gmdate('c'),
            'agents' => $agents,
            'open_tasks' => $openTasks,
            'locks' => $this->storage->listLocks(),
            'boards' => $boards,
            'queues' => $queues,
            'recent_events' => (new EventTools($this->storage))->coordination_event_log(null, 10)->getStructuredContent()['events'] ?? [],
        ];
        return ToolResult::structured($this->renderText($data), $data);
    }

    /** Compact markdown rendering of the snapshot for the text block. */
    private function renderText(array $d): string {
        $lines = ['# Board Status — ' . $d['now'], ''];
        $lines[] = '## Agents (' . count($d['agents']) . ')';
        foreach ($d['agents'] as $a) {
            $line = '- ' . $a['agent'] . ($a['label'] ? " ({$a['label']})" : '');
            $line .= $a['last_seen_s_ago'] === null ? ', no heartbeat' : ", last seen {$a['last_seen_s_ago']}s ago";
            if ($a['working_on']) { $line .= ", working on: {$a['working_on']}"; }
            if ($a['sessions']) { $line .= ' — ' . count($a['sessions']) . ' session(s)'; }
            $lines[] = $line;
            foreach ($a['sessions'] as $s) {
                $sub = '  - ' . substr($s['session'], 0, 8);
                $sub .= $s['last_seen_s_ago'] === null ? '' : " ({$s['last_seen_s_ago']}s ago)";
                if ($s['working_on']) { $sub .= " — {$s['working_on']}"; }
                $lines[] = $sub;
            }
        }
        if (!$d['agents']) { $lines[] = '(none)'; }
        $lines[] = '';
        $lines[] = '## Open tasks (' . count($d['open_tasks']) . ')';
        foreach ($d['open_tasks'] as $t) { $lines[] = TaskTools::taskLine($t['task'], $t) . " [{$t['scope']}]"; }
        if (!$d['open_tasks']) { $lines[] = '(none)'; }
        $lines[] = '';
        $lines[] = '## Locks (' . count($d['locks']) . ')';
        foreach ($d['locks'] as $name => $lock) {
            $lines[] = "- {$name} — {$lock['owner']} ({$lock['ttl_ms']}ms TTL)";
        }
        if (!$d['locks']) { $lines[] = '(none)'; }
        $lines[] = '';
        $lines[] = '## Boards';
        foreach ($d['boards'] as $scope => $n) { $lines[] = "- {$scope}: {$n} keys"; }
        if (!$d['boards']) { $lines[] = '(none)'; }
        $lines[] = '';
        $lines[] = '## Queues';
        foreach ($d['queues'] as $scope => $n) { $lines[] = "- {$scope}: {$n} pending"; }
        if (!$d['queues']) { $lines[] = '(none)'; }
        $lines[] = '';
        $lines[] = '## Recent events (' . count($d['recent_events']) . ')';
        foreach ($d['recent_events'] as $e) {
            $lines[] = '- ' . ($e['ts'] ?? '?') . ' ' . ($e['agent'] ?? '?') . ' ' . ($e['type'] ?? '?');
        }
        if (!$d['recent_events']) { $lines[] = '(none)'; }
        return implode("\n", $lines);
    }

    private const STATUS_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'now' => ['type' => 'string'],
            'agents' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'agent' => ['type' => 'string'],
                    'label' => ['type' => ['string', 'null']],
                    'capabilities' => true,
                    'working_on' => ['type' => ['string', 'null']],
                    'sessions' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'session' => ['type' => 'string'],
                            'working_on' => ['type' => ['string', 'null']],
                            'last_seen_s_ago' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['session', 'working_on', 'last_seen_s_ago'],
                    ]],
                    'last_seen_s_ago' => ['type' => ['integer', 'null']],
                    'registered_at' => ['type' => ['string', 'null']],
                ],
                'required' => ['agent', 'label', 'capabilities', 'working_on', 'sessions', 'last_seen_s_ago', 'registered_at'],
            ]],
            'open_tasks' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'task' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'scope' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'owner' => ['type' => ['string', 'null']],
                    'phorge_task_id' => ['type' => ['string', 'null']],
                    'priority' => ['type' => 'integer'],
                ],
                'required' => ['task', 'title', 'scope', 'status', 'owner', 'phorge_task_id', 'priority'],
            ]],
            'locks' => ['type' => 'object'],
            'boards' => ['type' => 'object'],
            'queues' => ['type' => 'object'],
            'recent_events' => ['type' => 'array'],
        ],
        'required' => ['now', 'agents', 'open_tasks', 'locks', 'boards', 'queues', 'recent_events'],
    ];
}
