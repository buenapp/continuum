<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
use EnchiladaMCP\ElicitationRequired;
use Continuum\Storage\ContinuumStorage;
use Continuum\Bridge\MilestoneSyncAdapterInterface;
use Continuum\Bridge\NullMilestoneSyncAdapter;

/**
 * Task tools: durable tasks on CouchDB, mirrored vertices in ArcadeDB,
 * pending ids queued in ValKey.
 *
 * Claims are optimistic-concurrency guarded: task_claim writes with the
 * observed MVCC revision, so a racing claim gets a 409 instead of a
 * silent double-claim. Status changes and handoffs are restricted to the
 * current owner. Every mutation lands in the append-only event log.
 */
class TaskTools {

    public const STATUSES = ['pending', 'claimed', 'in_progress', 'blocked', 'review', 'done', 'cancelled'];

    private MilestoneSyncAdapterInterface $milestones;

    public function __construct(
        private ContinuumStorage $storage,
        ?MilestoneSyncAdapterInterface $milestones = null,
    ) {
        $this->milestones = $milestones ?? new NullMilestoneSyncAdapter();
    }

    /**
     * Create a task. Returns the new task id; dependencies (if any) are
     * recorded in the task graph, and the task is queued for claim.
     */
    #[McpTool(
        name: 'task_create',
        description: 'Create a task on the blackboard. Status starts as pending; dependencies are recorded in the task graph and the task id is queued for claim.'
    )]
    public function task_create(
        string $title,
        ?string $scope = null,
        ?string $assignee = null,
        ?string $phorgeTaskId = null,
        int $priority = 2,
        ?array $dependsOn = null,
    ): array {
        $scope = ($scope === null || $scope === '') ? 'global' : $scope;
        // Same segment rules as board scopes (queue name and doc structure).
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $scope)) {
            throw new \InvalidArgumentException("invalid scope '{$scope}'");
        }
        $now = gmdate('c');
        // CouchDB _uuids are sequential per node (stable prefix), so ids use
        // a random suffix with retry-on-collision instead.
        $taskId = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $taskId = 'T-' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $this->storage->saveTask($taskId, $task = [
                    'title' => $title,
                    'scope' => $scope,
                    'status' => 'pending',
                    'owner' => null,
                    'assignee' => $assignee,
                    'phorge_task_id' => $phorgeTaskId,
                    'priority' => $priority,
                    'created_by' => CONTINUUM_AGENT,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'notes' => [],
                    'handoffs' => [],
                ]);
                break;
            } catch (\RuntimeException $e) {
                if ($attempt === 2 || !str_contains($e->getMessage(), 'revision conflict')) { throw $e; }
            }
        }
        foreach ($dependsOn ?? [] as $parentId) {
            $this->storage->linkTasks($taskId, $parentId);
        }
        $this->storage->enqueueTask($task['scope'], $taskId, ['title' => $title, 'priority' => $priority]);
        $this->storage->appendLog(CONTINUUM_AGENT, 'task_create', [
            'task' => $taskId, 'title' => $title, 'scope' => $task['scope'], 'assignee' => $assignee,
        ]);
        return $this->summarize($taskId, $task);
    }

    /**
     * List tasks, optionally filtered by scope, status, and/or owner.
     */
    #[McpTool(
        name: 'task_list',
        description: 'List tasks with optional scope/status/owner filters. Statuses: ' . self::STATUSES_CSV,
        readOnlyHint: true,
        outputSchema: self::TASK_LIST_SCHEMA
    )]
    public function task_list(?string $scope = null, ?string $status = null, ?string $owner = null, int $limit = 50): ToolResult {
        $tasks = [];
        foreach ($this->storage->listTaskDocs() as $id => $doc) {
            if ($scope !== null && ($doc['scope'] ?? 'global') !== $scope) { continue; }
            if ($status !== null && ($doc['status'] ?? '') !== $status) { continue; }
            if ($owner !== null && ($doc['owner'] ?? null) !== $owner) { continue; }
            $tasks[] = $this->summarize($id, $doc);
        }
        usort($tasks, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        $data = ['count' => min(count($tasks), $limit), 'total' => count($tasks), 'tasks' => array_slice($tasks, 0, $limit)];

        $filters = [];
        if ($scope !== null) { $filters[] = "scope={$scope}"; }
        if ($status !== null) { $filters[] = "status={$status}"; }
        if ($owner !== null) { $filters[] = "owner={$owner}"; }
        $lines = ["# Tasks ({$data['count']} of {$data['total']})" . ($filters ? ' — ' . implode(', ', $filters) : '')];
        if ($data['tasks'] === []) {
            $lines[] = '(none)';
        } else {
            foreach ($data['tasks'] as $t) { $lines[] = self::taskLine($t['task'], $t); }
        }
        return ToolResult::structured(implode("\n", $lines), $data);
    }

    /**
     * Claim a pending task. Atomic: a raced claim fails with a conflict
     * error instead of double-claiming. A task already held by another
     * agent (claimed, in_progress, blocked) can be stolen after
     * confirmation.
     */
    #[McpTool(
        name: 'task_claim',
        description: 'Atomically claim a pending task for your agent identity. Fails when the task is not pending or another agent holds it. A held task can be stolen: leave `confirm` null to be prompted (MRTR elicitation), or pass the answer object directly, e.g. {action: "accept", content: {approve: true}}.'
    )]
    public function task_claim(string $taskId, ?array $confirm = null): array {
        $task = $this->storage->loadTask($taskId)
            ?? throw new \RuntimeException("task {$taskId} not found");
        $stealFrom = null;
        if (($task['status'] ?? 'pending') !== 'pending' || !empty($task['owner'])) {
            $owner = $task['owner'] ?? null;
            $status = $task['status'] ?? '?';
            $stealable = $owner !== null
                && $owner !== CONTINUUM_AGENT
                && in_array($status, ['claimed', 'in_progress', 'blocked'], true);
            if (!$stealable) {
                throw new \RuntimeException("task {$taskId} is not claimable (status={$status}, owner=" . ($owner ?? 'none') . ')');
            }
            $answer = ElicitationRequired::answer($confirm);
            if ($answer === null) {
                throw new ElicitationRequired('confirm', "Task {$taskId} is held by {$owner} (status {$status}). Steal the claim?", [
                    'type' => 'object',
                    'properties' => ['approve' => ['type' => 'boolean', 'title' => 'Steal the claim']],
                    'required' => ['approve'],
                ]);
            }
            if (!$answer) {
                throw new \RuntimeException("claim steal declined: task {$taskId} remains with {$owner}");
            }
            $stealFrom = $owner;
        }
        $task['status'] = 'claimed';
        $task['owner'] = CONTINUUM_AGENT;
        $task['claimed_at'] = gmdate('c');
        $task['updated_at'] = gmdate('c');
        $this->saveGuarded($taskId, $task, $task['_rev'] ?? null);
        if ($stealFrom !== null) {
            // Replace the prior owner's CLAIMED_BY edge
            $this->storage->unclaim($taskId);
        }
        $this->storage->mapAgentRelationship(CONTINUUM_AGENT, $taskId);
        $this->storage->appendLog(CONTINUUM_AGENT, $stealFrom ? 'task_steal' : 'task_claim',
            $stealFrom ? ['task' => $taskId, 'from' => $stealFrom] : ['task' => $taskId]);
        $this->milestones->syncMilestone($taskId, 'started', [
            'agent' => CONTINUUM_AGENT, 'title' => $task['title'] ?? '', 'phorge_task_id' => $task['phorge_task_id'] ?? null,
        ]);
        return $this->summarize($taskId, $task);
    }

    /**
     * Update a task's status, optionally attaching a note. Only the
     * current owner may move a task. Terminal statuses (done, cancelled)
     * release the claim edge.
     */
    #[McpTool(
        name: 'task_update_status',
        description: 'Move a task through its state machine (' . self::STATUSES_CSV . '). Only the owning agent may update; done/cancelled release the claim.'
    )]
    public function task_update_status(string $taskId, string $status, ?string $note = null): array {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \RuntimeException('invalid status; expected one of: ' . self::STATUSES_CSV);
        }
        $task = $this->storage->loadTask($taskId)
            ?? throw new \RuntimeException("task {$taskId} not found");
        $this->requireOwner($taskId, $task);
        $from = $task['status'] ?? 'pending';
        if ($note !== null && $note !== '') {
            $task['notes'][] = ['agent' => CONTINUUM_AGENT, 'ts' => gmdate('c'), 'text' => $note];
        }
        $task['status'] = $status;
        $task['updated_at'] = gmdate('c');
        if ($status === 'done' || $status === 'cancelled') {
            $task['completed_by'] = $task['owner'];
            $task['completed_at'] = gmdate('c');
            $task['owner'] = null;
        } elseif ($status === 'pending') {
            $task['owner'] = null;
        }
        $this->saveGuarded($taskId, $task, $task['_rev'] ?? null);
        if ($task['owner'] === null) {
            $this->storage->unclaim($taskId);
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'task_status', ['task' => $taskId, 'from' => $from, 'to' => $status, 'note' => $note]);
        if ($status === 'blocked' || $status === 'done') {
            $this->milestones->syncMilestone($taskId, $status === 'blocked' ? 'blocked' : 'resolved', [
                'agent' => CONTINUUM_AGENT, 'title' => $task['title'] ?? '',
                'reason' => $status === 'blocked' ? $note : null,
                'summary' => $status === 'done' ? $note : null,
                'phorge_task_id' => $task['phorge_task_id'] ?? null,
            ]);
        }
        return $this->summarize($taskId, $task);
    }

    /**
     * Hand a task off: attach a structured handoff document, clear the
     * claim, and return the task to the pending queue for another agent.
     */
    #[McpTool(
        name: 'task_handoff',
        description: 'Attach a handoff document (summary, next steps, blockers), release your claim, and return the task to the pending queue.'
    )]
    public function task_handoff(string $taskId, string $summary, ?string $nextSteps = null, ?array $blockers = null): array {
        $task = $this->storage->loadTask($taskId)
            ?? throw new \RuntimeException("task {$taskId} not found");
        $this->requireOwner($taskId, $task);
        $task['handoffs'][] = [
            'agent' => CONTINUUM_AGENT,
            'ts' => gmdate('c'),
            'summary' => $summary,
            'next_steps' => $nextSteps,
            'blockers' => $blockers,
        ];
        $task['status'] = 'pending';
        $task['owner'] = null;
        $task['updated_at'] = gmdate('c');
        $this->saveGuarded($taskId, $task, $task['_rev'] ?? null);
        $this->storage->unclaim($taskId);
        $this->storage->enqueueTask($task['scope'] ?? 'global', $taskId, [
            'title' => $task['title'] ?? '', 'priority' => $task['priority'] ?? 2,
        ]);
        $this->storage->appendLog(CONTINUUM_AGENT, 'task_handoff', ['task' => $taskId, 'summary' => $summary]);
        return $this->summarize($taskId, $task);
    }

    /** MVCC write with a friendly conflict message. */
    private function saveGuarded(string $taskId, array $task, ?string $rev): void {
        try {
            $this->storage->saveTask($taskId, $task, $rev);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'revision conflict')) {
                throw new \RuntimeException("task {$taskId} changed concurrently; reload and retry");
            }
            throw $e;
        }
    }

    private function requireOwner(string $taskId, array $task): void {
        if (($task['owner'] ?? null) !== CONTINUUM_AGENT) {
            throw new \RuntimeException("task {$taskId} is owned by " . ($task['owner'] ?? 'nobody'));
        }
    }

    private const STATUSES_CSV = 'pending, claimed, in_progress, blocked, review, done, cancelled';

    private const TASK_SUMMARY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'task' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'scope' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['pending', 'claimed', 'in_progress', 'blocked', 'review', 'done', 'cancelled']],
            'owner' => ['type' => ['string', 'null']],
            'assignee' => ['type' => ['string', 'null']],
            'phorge_task_id' => ['type' => ['string', 'null']],
            'priority' => ['type' => 'integer'],
            'updated_at' => ['type' => ['string', 'null']],
        ],
        'required' => ['task', 'title', 'scope', 'status', 'owner', 'assignee', 'phorge_task_id', 'priority', 'updated_at'],
    ];

    private const TASK_LIST_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'tasks' => ['type' => 'array', 'items' => self::TASK_SUMMARY_SCHEMA],
        ],
        'required' => ['count', 'total', 'tasks'],
    ];

    private function summarize(string $taskId, array $task): array {
        return [
            'task' => $taskId,
            'title' => $task['title'] ?? '',
            'scope' => $task['scope'] ?? 'global',
            'status' => $task['status'] ?? 'pending',
            'owner' => $task['owner'] ?? null,
            'assignee' => $task['assignee'] ?? null,
            'phorge_task_id' => $task['phorge_task_id'] ?? null,
            'priority' => $task['priority'] ?? 2,
            'updated_at' => $task['updated_at'] ?? null,
        ];
    }

    /**
     * Render one task line for human-facing text output. Title leads and
     * the internal coordination id trails; the Phorge task id surfaces
     * beside it when one is linked.
     */
    public static function taskLine(string $taskId, array $task): string {
        $meta = [$task['status'] ?? '?', 'p' . ($task['priority'] ?? 2)];
        if (($task['owner'] ?? null) !== null) { $meta[] = '@' . $task['owner']; }
        $ref = $taskId;
        if (!empty($task['phorge_task_id'])) { $ref .= ' · Phorge ' . $task['phorge_task_id']; }
        return sprintf('- %s [%s] (%s)', $task['title'] ?? '', implode(', ', $meta), $ref);
    }
}
