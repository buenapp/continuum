<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use Continuum\Storage\ContinuumStorage;

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

    public function __construct(private ContinuumStorage $storage) {}

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
        readOnlyHint: true
    )]
    public function task_list(?string $scope = null, ?string $status = null, ?string $owner = null, int $limit = 50): array {
        $tasks = [];
        foreach ($this->storage->listTaskDocs() as $id => $doc) {
            if ($scope !== null && ($doc['scope'] ?? 'global') !== $scope) { continue; }
            if ($status !== null && ($doc['status'] ?? '') !== $status) { continue; }
            if ($owner !== null && ($doc['owner'] ?? null) !== $owner) { continue; }
            $tasks[] = $this->summarize($id, $doc);
        }
        usort($tasks, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        return ['count' => min(count($tasks), $limit), 'total' => count($tasks), 'tasks' => array_slice($tasks, 0, $limit)];
    }

    /**
     * Claim a pending task. Atomic: a raced claim fails with a conflict
     * error instead of double-claiming.
     */
    #[McpTool(
        name: 'task_claim',
        description: 'Atomically claim a pending task for your agent identity. Fails when the task is not pending or another agent holds it.'
    )]
    public function task_claim(string $taskId): array {
        $task = $this->storage->loadTask($taskId)
            ?? throw new \RuntimeException("task {$taskId} not found");
        if (($task['status'] ?? 'pending') !== 'pending' || !empty($task['owner'])) {
            throw new \RuntimeException("task {$taskId} is not claimable (status=" . ($task['status'] ?? '?')
                . ', owner=' . ($task['owner'] ?? 'none') . ')');
        }
        $task['status'] = 'claimed';
        $task['owner'] = CONTINUUM_AGENT;
        $task['claimed_at'] = gmdate('c');
        $task['updated_at'] = gmdate('c');
        $this->saveGuarded($taskId, $task, $task['_rev'] ?? null);
        $this->storage->mapAgentRelationship(CONTINUUM_AGENT, $taskId);
        $this->storage->appendLog(CONTINUUM_AGENT, 'task_claim', ['task' => $taskId]);
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

    private function summarize(string $taskId, array $task): array {
        return [
            'task' => $taskId,
            'title' => $task['title'] ?? '',
            'scope' => $task['scope'] ?? 'global',
            'status' => $task['status'] ?? 'pending',
            'owner' => $task['owner'] ?? null,
            'assignee' => $task['assignee'] ?? null,
            'priority' => $task['priority'] ?? 2,
            'updated_at' => $task['updated_at'] ?? null,
        ];
    }
}
