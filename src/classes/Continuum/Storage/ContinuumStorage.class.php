<?php

namespace Continuum\Storage;

use Continuum\Scope;

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
        $this->valkey->enqueueTask(Scope::canonical($queue), $taskId, $payload);
    }
    public function claimQueuedTask(string $queue): ?array {
        return $this->valkey->claimTask(Scope::canonical($queue));
    }
    public function releaseToQueue(string $queue, string $taskId): void {
        $this->valkey->releaseToQueue(Scope::canonical($queue), $taskId);
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
    /** Break-glass release ignoring ownership; callers log the prior owner. */
    public function forceReleaseLock(string $name): bool {
        $released = $this->valkey->forceReleaseLock($name);
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
    /** Session-scoped presence record; TTL-refreshed per beat. */
    public function sessionHeartbeat(string $agentId, string $sessionId, array $meta = []): void {
        $this->valkey->sessionHeartbeat($agentId, $sessionId, $meta);
        if ($meta !== []) {
            $this->publishChanges(['continuum://agents', "continuum://agents/{$agentId}"]);
        }
    }
    /** Live session records for an agent (expired cards pruned on read). */
    public function sessionRecords(string $agentId): array {
        return $this->valkey->sessionRecords($agentId);
    }
    public function agents(): array {
        return $this->valkey->agents();
    }
    /** Registered agents with their newest heartbeat unix timestamp. */
    public function presence(): array {
        $out = [];
        foreach ($this->valkey->agents() as $agentId) {
            $out[$agentId] = $this->valkey->lastSeen($agentId);
        }
        return $out;
    }
    /** Registered agents with their full presence records (meta + heartbeat + sessions). */
    public function agentDirectory(): array {
        $out = [];
        foreach ($this->valkey->agents() as $agentId) {
            $record = $this->valkey->agentRecord($agentId) ?? [];
            $sessions = $this->valkey->sessionRecords($agentId);
            $record['sessions'] = $sessions;
            $ts = $this->valkey->agentHeartbeatTime($agentId);
            foreach ($sessions as $rec) {
                $sTs = isset($rec['heartbeat']) ? (int)$rec['heartbeat'] : null;
                if ($sTs !== null) { $ts = max($ts ?? 0, $sTs); }
            }
            if ($ts !== null) { $record['heartbeat'] = (string)$ts; }
            $out[$agentId] = $record;
        }
        return $out;
    }
    /** Destructive legacy pull of up to $limit untargeted inbox messages. */
    public function inboxPull(string $agentId, int $limit): array {
        return $this->valkey->inboxPull($agentId, $limit);
    }
    /** All currently held locks: name => ['owner'=>, 'ttl_ms'=>]. */
    public function listLocks(): array {
        return $this->valkey->listLocks();
    }
    public function queueDepth(string $queue): int {
        return $this->valkey->queueDepth(Scope::canonical($queue));
    }
    /** All event-log documents (id => doc), unsorted. Callers filter/limit. */
    public function listEventDocs(): array {
        return $this->couch->listDocs('events');
    }
    public function inboxPush(string $agentId, array $message): int {
        return $this->valkey->inboxPush($agentId, $message);
    }
    /** Lease up to $limit messages for $session (null reads untargeted only); $includeUntargeted=false skips untargeted mail. */
    public function inboxLease(string $agentId, ?string $session, int $limit, int $ttlSeconds, bool $includeUntargeted = true): array {
        return $this->valkey->inboxLease($agentId, $session, $limit, $ttlSeconds, time(), $includeUntargeted);
    }
    /** Acknowledge (delete) inbox messages by id; returns the removed ids. */
    public function inboxAck(string $agentId, array $ids): array {
        return $this->valkey->inboxAck($agentId, $ids, time());
    }
    public function inboxDrain(string $agentId): array {
        return $this->valkey->inboxDrain($agentId);
    }
    /** Non-destructive inbox counts by target, including inactive sessions. */
    public function inboxSummary(string $agentId): array {
        return $this->valkey->inboxSummary($agentId);
    }

    // --- Durable, MVCC-aware
    public function savePlan(string $planId, array $plan, ?string $rev = null): array {
        return $this->couch->put('plans', $planId, $plan, $rev);
    }
    public function loadPlan(string $planId): ?array {
        return $this->couch->get('plans', $planId);
    }
    /** Workstream identity record (db 'identities'); doc id = workstream name. */
    public function saveIdentity(string $workstream, array $identity, ?string $rev = null): array {
        $wrote = $this->couch->put('identities', $workstream, $identity, $rev);
        $this->publishChanges(['continuum://identities', "continuum://identities/{$workstream}"]);
        return $wrote;
    }
    public function loadIdentity(string $workstream): ?array {
        return $this->couch->get('identities', $workstream);
    }
    public function listIdentities(): array {
        return $this->couch->listDocs('identities');
    }
    public function saveTask(string $taskId, array $task, ?string $rev = null): array {
        $task['scope'] = Scope::canonical($task['scope'] ?? null);
        $wrote = $this->couch->put('tasks', $taskId, $task, $rev);
        $this->arcade->upsertTaskNode($taskId, $task);
        $this->publishChanges(['continuum://tasks', "continuum://tasks/{$taskId}"]);
        return $wrote;
    }
    public function loadTask(string $taskId): ?array {
        $task = $this->couch->get('tasks', $taskId);
        if ($task !== null) { $task['scope'] = Scope::canonical($task['scope'] ?? null); }
        return $task;
    }
    public function listTaskIds(): array {
        return $this->couch->listIds('tasks');
    }
    public function listTaskDocs(): array {
        $tasks = $this->couch->listDocs('tasks');
        foreach ($tasks as &$task) { $task['scope'] = Scope::canonical($task['scope'] ?? null); }
        unset($task);
        return $tasks;
    }
    public function listBoardDocs(string $board): array {
        $prefix = Scope::canonical($board) . '/';
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
        if (isset($data['scope']) && is_string($data['scope'])) {
            $data['scope'] = Scope::canonical($data['scope']);
        }
        $id = $this->couch->appendLog($agentId, $type, $data);
        $this->publishChanges(['continuum://events']);
        return $id;
    }
    public function saveBoardEntry(string $board, string $key, array $entry, ?string $rev = null): array {
        $board = Scope::canonical($board);
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
        $board = Scope::canonical($board);
        return array_values(array_map(
            fn($id) => preg_replace('#^' . preg_quote($board . '/', '#') . '#', '', $id),
            array_filter($this->couch->listIds('boards'), fn($id) => str_starts_with($id, $board . '/'))
        ));
    }
    public function deleteBoardEntry(string $board, string $key, string $rev): void {
        $board = Scope::canonical($board);
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
        $board = Scope::canonical($board);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $key)) {
            throw new \InvalidArgumentException(
                "invalid key '{$key}': start with [A-Za-z0-9]; only letters, digits, '.', '_' and '-' allowed"
            );
        }
        return "{$board}/{$key}";
    }

    /**
     * Audit or merge case-variant board docs, task docs and task queues.
     * Conflicting values are reported before any mutation. Run with writes paused.
     */
    public function migrateScopes(bool $apply = false): array {
        $boards = $this->couch->listDocs('boards');
        $tasks = $this->couch->listDocs('tasks');
        $queues = $this->valkey->taskQueueScopes();
        $report = ['applied' => false, 'boards' => [], 'tasks' => [], 'queues' => [], 'conflicts' => []];
        $groups = [];

        foreach ($boards as $id => $doc) {
            $segments = explode('/', (string)$id, 2);
            if (count($segments) !== 2) { continue; }
            [$sourceScope, $key] = $segments;
            try {
                $target = Scope::canonical($sourceScope) . '/' . $key;
            } catch (\InvalidArgumentException $e) {
                $report['conflicts'][] = $e->getMessage() . " in board document {$id}";
                continue;
            }
            $groups[$target][$id] = $doc;
            if ($id !== $target) {
                if (empty($doc['_rev'])) { $report['conflicts'][] = "board document {$id} has no revision"; }
                $report['boards'][] = ['source' => $id, 'target' => $target];
            }
        }
        foreach ($groups as $target => $variants) {
            $baseline = null;
            foreach ($variants as $doc) {
                unset($doc['_id'], $doc['_rev']);
                if ($baseline === null) { $baseline = $doc; continue; }
                if ($doc !== $baseline) {
                    $report['conflicts'][] = "board entries for {$target} differ by case and value; merge them manually";
                    break;
                }
            }
        }
        foreach ($tasks as $id => $doc) {
            $source = $doc['scope'] ?? 'global';
            $target = Scope::canonical(is_string($source) ? $source : null);
            if ($source !== $target) {
                if (empty($doc['_rev'])) { $report['conflicts'][] = "task document {$id} has no revision"; }
                $report['tasks'][] = ['task' => $id, 'source' => $source, 'target' => $target];
            }
        }
        foreach ($queues as $scope) {
            $target = Scope::canonical($scope);
            if ($scope !== $target) { $report['queues'][] = ['source' => $scope, 'target' => $target]; }
        }
        if (!$apply || $report['conflicts'] !== []) { return $report; }

        $created = [];
        foreach ($report['boards'] as $move) {
            $source = $move['source'];
            $target = $move['target'];
            $doc = $groups[$target][$source];
            if (!isset($groups[$target][$target]) && !isset($created[$target])) {
                unset($doc['_id'], $doc['_rev']);
                $this->couch->put('boards', $target, $doc);
                $created[$target] = true;
            }
            $this->couch->deleteDoc('boards', $source, $groups[$target][$source]['_rev']);
        }
        foreach ($report['tasks'] as $move) {
            $id = $move['task'];
            $doc = $tasks[$id];
            $doc['scope'] = $move['target'];
            $rev = $doc['_rev'];
            unset($doc['_id']);
            $this->couch->put('tasks', $id, $doc, $rev);
        }
        foreach ($report['queues'] as $move) {
            $this->valkey->mergeTaskQueue($move['source'], $move['target']);
        }

        foreach ($this->couch->listDocs('boards') as $id => $_doc) {
            $scope = explode('/', (string)$id, 2)[0];
            if ($scope !== Scope::canonical($scope)) {
                throw new \RuntimeException("scope migration incomplete: board document {$id} remains");
            }
        }
        foreach ($this->couch->listDocs('tasks') as $id => $doc) {
            $scope = $doc['scope'] ?? 'global';
            if ($scope !== Scope::canonical(is_string($scope) ? $scope : null)) {
                throw new \RuntimeException("scope migration incomplete: task document {$id} remains in {$scope}");
            }
        }
        foreach ($this->valkey->taskQueueScopes() as $scope) {
            if ($scope !== Scope::canonical($scope)) {
                throw new \RuntimeException("scope migration incomplete: task queue {$scope} remains");
            }
        }
        $report['applied'] = true;
        return $report;
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
