<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use Continuum\Storage\ContinuumStorage;
use Continuum\Bridge\RankingProviderInterface;

/**
 * context_pack: the curated, token-budgeted brief agents are steered to
 * read at session start instead of raw board/task scans.
 *
 * Ranking is lexical/deterministic (status weight, priority, recency).
 * Embedding-based semantic ranking is a later phase and must slot in
 * behind the same ranking seam, not into the tool contract.
 */
class ContextTools {

    /** Rough characters-per-token estimate for budgeting. */
    private const CHARS_PER_TOKEN = 4;

    private const STATUS_WEIGHT = [
        'blocked' => 0, 'in_progress' => 1, 'claimed' => 2,
        'pending' => 3, 'review' => 4, 'done' => 5, 'cancelled' => 6,
    ];

    public function __construct(
        private ContinuumStorage $storage,
        private ?RankingProviderInterface $ranker = null,
    ) {}

    #[McpTool(
        name: 'context_pack',
        description: 'Session-start brief (prefer over raw scans): focused task with dependencies, open tasks in scope, recent board entries and agent presence, within tokenBudget (default 2000). query ranks tasks and entries by relevance.',
        readOnlyHint: true,
        outputSchema: self::PACK_SCHEMA
    )]
    public function context_pack(?string $scope = null, ?string $taskId = null, int $tokenBudget = 2000, ?string $query = null): ToolResult {
        if ($tokenBudget < 100) { $tokenBudget = 100; }
        $budget = $tokenBudget * self::CHARS_PER_TOKEN;
        $used = 0;
        $omitted = ['tasks' => 0, 'board' => 0];
        $sections = [];

        $emit = function (string $line) use (&$sections, &$used, $budget): bool {
            $cost = strlen($line) + 1;
            if ($used + $cost > $budget) { return false; }
            $sections[] = $line;
            $used += $cost;
            return true;
        };

        $emit('# Context Pack (scope: ' . ($scope ?? 'global') . ')');

        // Focused task with graph neighborhood
        if ($taskId !== null) {
            $task = $this->storage->loadTask($taskId);
            if ($task === null) {
                throw new \RuntimeException("task {$taskId} not found");
            }
            $emit("\n## Focus: " . ($task['title'] ?? '') . ' [' . ($task['status'] ?? '?') . "] ({$taskId})");
            if (!empty($task['phorge_task_id'])) { $emit('- Phorge: ' . $task['phorge_task_id']); }
            if (!empty($task['notes'])) {
                foreach (array_slice($task['notes'], -3) as $n) {
                    $emit('- note(' . ($n['agent'] ?? '?') . '): ' . ($n['text'] ?? ''));
                }
            }
            if (!empty($task['handoffs'])) {
                $last = end($task['handoffs']);
                $emit('- last handoff(' . ($last['agent'] ?? '?') . '): ' . ($last['summary'] ?? ''));
            }
            foreach ($this->storage->getDependencies($taskId) as $dep) {
                $dep = (array)$dep;
                $emit('- depends on: ' . ($dep['title'] ?? '') . ' [' . ($dep['status'] ?? '?') . '] (' . ($dep['id'] ?? '?') . ')');
            }
            foreach ($this->storage->getDependents($taskId) as $dep) {
                $dep = (array)$dep;
                $emit('- blocks: ' . ($dep['title'] ?? '') . ' [' . ($dep['status'] ?? '?') . '] (' . ($dep['id'] ?? '?') . ')');
            }
        }

        // Open tasks in scope, ranked: status weight, then priority, then recency
        $tasks = [];
        foreach ($this->storage->listTaskDocs() as $id => $doc) {
            if ($scope !== null && ($doc['scope'] ?? 'global') !== $scope) { continue; }
            $status = $doc['status'] ?? 'pending';
            if ($status === 'done' || $status === 'cancelled') { continue; }
            $doc['_id'] = $id;
            $tasks[] = $doc;
        }
        usort($tasks, function ($a, $b) {
            $w = (self::STATUS_WEIGHT[$b['status'] ?? 'pending'] ?? 3) <=> (self::STATUS_WEIGHT[$a['status'] ?? 'pending'] ?? 3);
            if ($w !== 0) { return $w; }
            $p = ($b['priority'] ?? 2) <=> ($a['priority'] ?? 2);
            return $p !== 0 ? $p : strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });

        // Relevance ranking replaces the default ordering when a ranker is
        // configured and the caller supplied a query (or a focus task).
        if (($query === null || $query === '') && !empty($task['title'] ?? null)) { $query = $task['title']; }
        $rankItems = function (array $items) use ($query): array {
            if ($this->ranker === null || empty($items)) { return $items; }
            $q = ($query !== null && $query !== '') ? $query : null;
            if ($q === null) { return $items; }
            try {
                return $this->ranker->rank($items, $q);
            } catch (\Throwable) {
                return $items; // ranking must never break the pack
            }
        };

        $taskItems = array_map(function ($doc) {
            return [
                'text' => ($doc['title'] ?? '') . ' ' . ($doc['status'] ?? ''),
                'line' => TaskTools::taskLine($doc['_id'], $doc),
            ];
        }, $tasks);
        $emit("\n## Open tasks (" . count($tasks) . ')');
        foreach ($rankItems($taskItems) as $item) {
            if (!$emit($item['line'])) { $omitted['tasks']++; }
        }

        // Board entries: own scope + global, recent first
        foreach (array_unique([$scope ?? 'global', 'global']) as $board) {
            $docs = $this->storage->listBoardDocs($board);
            if (empty($docs)) { continue; }
            uasort($docs, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
            $emit("\n## Board: {$board}");
            $boardItems = [];
            foreach ($docs as $key => $doc) {
                $value = $doc['value'] ?? null;
                $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
                $boardItems[] = [
                    'text' => $key . ' ' . $text,
                    'line' => '- ' . $key . ' (by ' . ($doc['updated_by'] ?? '?') . '): ' . $text,
                ];
            }
            foreach ($rankItems($boardItems) as $item) {
                if (!$emit($item['line'])) { $omitted['board']++; }
            }
        }

        // Agent presence (identity + per-session working-on)
        $directory = $this->storage->agentDirectory();
        if (!empty($directory)) {
            $emit("\n## Agents");
            foreach ($directory as $agentId => $record) {
                $ts = isset($record['heartbeat']) && $record['heartbeat'] !== '' ? (int)$record['heartbeat'] : null;
                $age = $ts === null ? 'no heartbeat' : (time() - $ts) . 's ago';
                $sessions = [];
                foreach ((array)($record['sessions'] ?? []) as $sid => $srec) {
                    $sAge = isset($srec['heartbeat']) ? (time() - (int)$srec['heartbeat']) . 's ago' : 'never';
                    $sessions[] = substr($sid, 0, 8) . ': ' . ($srec['working_on'] ?? 'idle') . " ({$sAge})";
                }
                $emit('- ' . $agentId . ' (last seen ' . $age . ')'
                    . ($sessions ? ' — sessions: ' . implode('; ', $sessions) : ''));
            }
        }

        $pack = implode("\n", $sections);
        return ToolResult::structured($pack, [
            'scope' => $scope ?? 'global',
            'task' => $taskId,
            'est_tokens' => (int)ceil($used / self::CHARS_PER_TOKEN),
            'omitted' => $omitted,
            'pack' => $pack,
        ]);
    }

    private const PACK_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'scope' => ['type' => 'string'],
            'task' => ['type' => ['string', 'null']],
            'est_tokens' => ['type' => 'integer'],
            'omitted' => [
                'type' => 'object',
                'properties' => [
                    'tasks' => ['type' => 'integer'],
                    'board' => ['type' => 'integer'],
                ],
                'required' => ['tasks', 'board'],
            ],
            'pack' => ['type' => 'string'],
        ],
        'required' => ['scope', 'task', 'est_tokens', 'omitted', 'pack'],
    ];
}
