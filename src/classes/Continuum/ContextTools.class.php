<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use Continuum\Storage\ContinuumStorage;

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

    public function __construct(private ContinuumStorage $storage) {}

    #[McpTool(
        name: 'context_pack',
        description: 'Return a curated, ranked, token-budgeted brief of the blackboard: focused task (if any) with dependencies, open tasks in scope, recent board entries, and agent presence. Prefer this over raw scans at session start.',
        readOnlyHint: true
    )]
    public function context_pack(?string $scope = null, ?string $taskId = null, int $tokenBudget = 2000): array {
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
            $emit("\n## Focus: {$taskId} — " . ($task['title'] ?? '') . ' [' . ($task['status'] ?? '?') . ']');
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
                $emit('- depends on ' . ($dep['id'] ?? '?') . ': ' . ($dep['title'] ?? '') . ' [' . ($dep['status'] ?? '?') . ']');
            }
            foreach ($this->storage->getDependents($taskId) as $dep) {
                $dep = (array)$dep;
                $emit('- blocks ' . ($dep['id'] ?? '?') . ': ' . ($dep['title'] ?? '') . ' [' . ($dep['status'] ?? '?') . ']');
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
        $emit("\n## Open tasks (" . count($tasks) . ')');
        foreach ($tasks as $doc) {
            $owner = $doc['owner'] ?? null;
            $line = '- ' . $doc['_id'] . ' [p' . ($doc['priority'] ?? 2) . ', ' . ($doc['status'] ?? '?')
                . ($owner ? ', @' . $owner : '') . '] ' . ($doc['title'] ?? '');
            if (!$emit($line)) { $omitted['tasks']++; }
        }

        // Board entries: own scope + global, recent first
        foreach ([$scope ?? 'global', 'global'] as $board) {
            $docs = $this->storage->listBoardDocs($board);
            if (empty($docs)) { continue; }
            uasort($docs, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
            $emit("\n## Board: {$board}");
            foreach ($docs as $key => $doc) {
                $value = $doc['value'] ?? null;
                $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
                $line = '- ' . $key . ' (by ' . ($doc['updated_by'] ?? '?') . '): ' . $text;
                if (!$emit($line)) { $omitted['board']++; }
            }
            if ($board === 'global') { break; }
        }

        // Agent presence
        $presence = $this->storage->presence();
        if (!empty($presence)) {
            $emit("\n## Agents");
            foreach ($presence as $agentId => $ts) {
                $age = $ts === null ? 'no heartbeat' : (time() - $ts) . 's ago';
                $emit('- ' . $agentId . ' (last seen ' . $age . ')');
            }
        }

        return [
            'scope' => $scope ?? 'global',
            'task' => $taskId,
            'est_tokens' => (int)ceil($used / self::CHARS_PER_TOKEN),
            'omitted' => $omitted,
            'pack' => implode("\n", $sections),
        ];
    }
}
