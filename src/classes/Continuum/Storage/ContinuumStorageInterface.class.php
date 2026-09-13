<?php

namespace Continuum\Storage;

/**
 * Unified storage contract for Continuum.
 *
 * Three engines behind one facade (see docs/PROTOTYPE.md §6):
 *   ephemeral  - ValKey  (queues, locks, signals, presence)
 *   durable    - CouchDB (plans, workflow snapshots, event log, task docs)
 *   structural - ArcadeDB (task dependency graph, agent relationships)
 */
interface ContinuumStorageInterface {

    // --- Ephemeral (ValKey)
    public function enqueueTask(string $queue, string $taskId, array $payload): void;
    public function claimQueuedTask(string $queue): ?array;   // ['id'=>, 'payload'=>] or null
    public function releaseToQueue(string $queue, string $taskId): void;
    public function publishSignal(string $channel, array $signal): void;
    public function acquireLock(string $name, string $ownerId, int $ttlSeconds): bool;
    public function releaseLock(string $name, string $ownerId): bool;
    public function checkLock(string $name): ?array;
    public function heartbeat(string $agentId, array $meta = []): void;
    public function agents(): array;
    public function inboxPush(string $agentId, array $message): int;
    public function inboxDrain(string $agentId): array;

    // --- Durable (CouchDB)
    public function savePlan(string $planId, array $plan, ?string $rev = null): array;
    public function loadPlan(string $planId): ?array;
    public function saveTask(string $taskId, array $task, ?string $rev = null): array;
    public function loadTask(string $taskId): ?array;
    public function listTaskIds(): array;
    public function appendLog(string $agentId, string $type, array $data): string;
    public function saveBoardEntry(string $board, string $key, array $entry, ?string $rev = null): array;
    public function loadBoardEntry(string $board, string $key): ?array;
    public function listBoardKeys(string $board): array;
    public function deleteBoardEntry(string $board, string $key, string $rev): void;

    // --- Structural (ArcadeDB)
    public function upsertTaskNode(string $taskId, array $props = []): void;
    public function linkTasks(string $childTaskId, string $parentTaskId): void;
    public function getDependencies(string $taskId): array;
    public function getDependents(string $taskId): array;
    public function mapAgentRelationship(string $agentId, string $taskId): void;
    public function unclaim(string $taskId): void;
}
