<?php

namespace Continuum\Storage;

/**
 * ContinuumStorage - the single facade tools talk to.
 *
 * Composition over the three engine adapters; keeps MVCC/event-log and
 * graph maintenance in one place so tools stay thin.
 */
class ContinuumStorage implements ContinuumStorageInterface {

    /** ValKey pub/sub channel name (under the store's signal: prefix)
     *  carrying resource-change notifications for MCP subscriptions. */
    public const CHANGES_CHANNEL = 'changes';

    public function __construct(
        private ValKeyStore $valkey,
        private CouchDBStore $couch,
        private ArcadeDBStore $arcade,
    ) {}

    /** Build from $SETTINGS (IniConfig) or throw when a section is missing. */
    public static function fromSettings(?\Enchilada\Config\IniConfig $settings): self {
        if ($settings === null) {
            throw new \RuntimeException('settings.ini not loaded (storage engines unconfigured)');
        }
        $valkey = new ValKeyStore(new RespClient(
            $settings->getString('valkey', 'host', '127.0.0.1'),
            $settings->getInt('valkey', 'port', 6379)
        ));
        $couch = new CouchDBStore(
            $settings->getString('couchdb', 'host', '127.0.0.1'),
            $settings->getInt('couchdb', 'port', 5984),
            $settings->getString('couchdb', 'user', 'admin'),
            $settings->getString('couchdb', 'pass', ''),
            $settings->getString('couchdb', 'database_prefix', 'continuum_')
        );
        $arcade = new ArcadeDBStore(
            $settings->getString('arcadedb', 'host', '127.0.0.1'),
            $settings->getInt('arcadedb', 'port', 2480),
            $settings->getString('arcadedb', 'user', 'root'),
            $settings->getString('arcadedb', 'pass', ''),
            $settings->getString('arcadedb', 'database', 'continuum')
        );
        return new self($valkey, $couch, $arcade);
    }

    /** Idempotent graph schema bootstrap; call once per process. */
    public function ensureSchema(): void {
        $this->arcade->ensureSchema();
    }

    /**
     * Announce that the given resource URIs changed (MCP subscriptions).
     * Runs after the mutation it reports; a publish failure must never
     * fail the mutation itself.
     */
    public function publishChanges(array $uris): void {
        if ($uris === []) { return; }
        try {
            $this->valkey->publishSignal(self::CHANGES_CHANNEL, [
                'uris' => array_values($uris),
                'ts' => gmdate('c'),
                'agent' => defined('CONTINUUM_AGENT') ? CONTINUUM_AGENT : null,
            ]);
        } catch (\Throwable) {
            // best-effort notification only
        }
    }

    // --- Ephemeral passthrough
    public function enqueueTask(string $queue, string $taskId, array $payload): void {
        $this->valkey->enqueueTask($queue, $taskId, $payload);
    }
    public function claimQueuedTask(string $queue): ?array {
        return $this->valkey->claimTask($queue);
    }
    public function releaseToQueue(string $queue, string $taskId): void {
        $this->valkey->releaseToQueue($queue, $taskId);
    }
    public function publishSignal(string $channel, array $signal): void {
        $this->valkey->publishSignal($channel, $signal);
    }
    public function acquireLock(string $name, string $ownerId, int $ttlSeconds): bool {
        $acquired = $this->valkey->acquireLock($name, $ownerId, $ttlSeconds);
        if ($acquired) {
            $this->publishChanges(['continuum://locks', "continuum://locks/{$name}"]);
        }
        return $acquired;
    }
    public function releaseLock(string $name, string $ownerId): bool {
        $released = $this->valkey->releaseLock($name, $ownerId);
        if ($released) {
            $this->publishChanges(['continuum://locks', "continuum://locks/{$name}"]);
        }
        return $released;
    }
    public function checkLock(string $name): ?array {
        return $this->valkey->checkLock($name);
    }
    public function heartbeat(string $agentId, array $meta = []): void {
        $this->valkey->heartbeat($agentId, $meta);
        // Bare heartbeat ticks are presence churn and do not notify; the
        // agent directory only meaningfully changes when meta is written.
        if ($meta !== []) {
            $this->publishChanges(['continuum://agents', "continuum://agents/{$agentId}"]);
        }
    }
    public function agents(): array {
        return $this->valkey->agents();
    }
    /** Registered agents with their last heartbeat unix timestamp. */
    public function presence(): array {
        $out = [];
        foreach ($this->valkey->agents() as $agentId) {
            $out[$agentId] = $this->valkey->agentHeartbeatTime($agentId);
        }
        return $out;
    }
    /** Registered agents with their full presence records (meta + heartbeat). */
    public function agentDirectory(): array {
        $out = [];
        foreach ($this->valkey->agents() as $agentId) {
            $out[$agentId] = $this->valkey->agentRecord($agentId) ?? [];
        }
        return $out;
    }
    /** Read up to $limit inbox messages, leaving the remainder queued. */
    public function inboxPull(string $agentId, int $limit): array {
        return $this->valkey->inboxPull($agentId, $limit);
    }
    /** All currently held locks: name => ['owner'=>, 'ttl_ms'=>]. */
    public function listLocks(): array {
        return $this->valkey->listLocks();
    }
    public function queueDepth(string $queue): int {
        return $this->valkey->queueDepth($queue);
    }
    /** All event-log documents (id => doc), unsorted. Callers filter/limit. */
    public function listEventDocs(): array {
        return $this->couch->listDocs('events');
    }
    public function inboxPush(string $agentId, array $message): int {
        return $this->valkey->inboxPush($agentId, $message);
    }
    public function inboxDrain(string $agentId): array {
        return $this->valkey->inboxDrain($agentId);
    }

    // --- Durable, MVCC-aware
    public function savePlan(string $planId, array $plan, ?string $rev = null): array {
        return $this->couch->put('plans', $planId, $plan, $rev);
    }
    public function loadPlan(string $planId): ?array {
        return $this->couch->get('plans', $planId);
    }
    public function saveTask(string $taskId, array $task, ?string $rev = null): array {
        $wrote = $this->couch->put('tasks', $taskId, $task, $rev);
        $this->arcade->upsertTaskNode($taskId, $task);
        $this->publishChanges(['continuum://tasks', "continuum://tasks/{$taskId}"]);
        return $wrote;
    }
    public function loadTask(string $taskId): ?array {
        return $this->couch->get('tasks', $taskId);
    }
    public function listTaskIds(): array {
        return $this->couch->listIds('tasks');
    }
    public function listTaskDocs(): array {
        return $this->couch->listDocs('tasks');
    }
    public function listBoardDocs(string $board): array {
        $prefix = $board . '/';
        $docs = [];
        foreach ($this->couch->listDocs('boards') as $id => $doc) {
            if (str_starts_with($id, $prefix)) { $docs[substr($id, strlen($prefix))] = $doc; }
        }
        return $docs;
    }
    /** All board docs with their full "scope/key" ids. */
    public function listBoardDocsAll(): array {
        return $this->couch->listDocs('boards');
    }
    public function newId(): string {
        return $this->couch->newId();
    }
    public function appendLog(string $agentId, string $type, array $data): string {
        $id = $this->couch->appendLog($agentId, $type, $data);
        $this->publishChanges(['continuum://events']);
        return $id;
    }
    public function saveBoardEntry(string $board, string $key, array $entry, ?string $rev = null): array {
        $wrote = $this->couch->put('boards', self::boardId($board, $key), $entry, $rev);
        $this->publishChanges([
            'continuum://board/index',
            "continuum://board/{$board}/{$key}",
            "continuum://snapshot/{$board}",
        ]);
        return $wrote;
    }
    public function loadBoardEntry(string $board, string $key): ?array {
        return $this->couch->get('boards', self::boardId($board, $key));
    }
    public function listBoardKeys(string $board): array {
        return array_map(
            fn($id) => preg_replace('#^' . preg_quote($board . '/', '#') . '#', '', $id),
            array_filter($this->couch->listIds('boards'), fn($id) => str_starts_with($id, $board . '/'))
        );
    }
    public function deleteBoardEntry(string $board, string $key, string $rev): void {
        $this->couch->deleteDoc('boards', self::boardId($board, $key), $rev);
        $this->publishChanges([
            'continuum://board/index',
            "continuum://board/{$board}/{$key}",
            "continuum://snapshot/{$board}",
        ]);
    }

    /**
     * Board doc ids are "{scope}/{key}". Segments must not begin with '_'
     * (CouchDB reserves that namespace) and must not contain '/'.
     */
    private static function boardId(string $board, string $key): string {
        foreach (['scope' => $board, 'key' => $key] as $what => $seg) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $seg)) {
                throw new \InvalidArgumentException(
                    "invalid {$what} '{$seg}': start with [A-Za-z0-9]; only letters, digits, '.', '_' and '-' allowed"
                );
            }
        }
        return "{$board}/{$key}";
    }

    // --- Structural passthrough
    public function upsertTaskNode(string $taskId, array $props = []): void {
        $this->arcade->upsertTaskNode($taskId, $props);
    }
    public function linkTasks(string $childTaskId, string $parentTaskId): void {
        $this->arcade->linkTasks($childTaskId, $parentTaskId);
    }
    public function getDependencies(string $taskId): array {
        return $this->arcade->getDependencies($taskId);
    }
    public function getDependents(string $taskId): array {
        return $this->arcade->getDependents($taskId);
    }
    public function mapAgentRelationship(string $agentId, string $taskId): void {
        $this->arcade->mapAgentRelationship($agentId, $taskId);
    }
    public function unclaim(string $taskId): void {
        $this->arcade->unclaim($taskId);
    }
}
