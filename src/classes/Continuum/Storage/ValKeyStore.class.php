<?php

namespace Continuum\Storage;

/**
 * Ephemeral engine adapter: atomic coordination state backed by ValKey.
 *
 * Key schema (all prefixed "continuum:"):
 *   queue:{queue}            LIST  pending task ids
 *   task:{id}                HASH  task payload snapshot for claim hand-off
 *   lock:{name}              STR   advisory lock; value = owner; TTL enforced
 *   agent:{id}               HASH  presence record (heartbeat timestamp, meta)
 *   agents                   SET   registered agent ids
 *   inbox:{agentId}          LIST  inbound messages (JSON)
 *   signal:{channel}         PUBSUB channels (no persistence)
 */
class ValKeyStore {

    private const NS = 'continuum:';
    private const LOCK_RELEASE_LUA = 'if redis.call("get",KEYS[1]) == ARGV[1] then return redis.call("del",KEYS[1]) else return 0 end';

    private RespClient $client;

    public function __construct(RespClient $client) {
        $this->client = $client;
    }

    /** Append a task id to a queue and store its payload for claim hand-off. */
    public function enqueueTask(string $queue, string $taskId, array $payload): void {
        $this->client->command('HSET', self::NS . 'task:' . $taskId, 'payload', json_encode($payload));
        $this->client->command('RPUSH', self::NS . 'queue:' . $queue, $taskId);
    }

    /**
     * Pop the next pending task id, returning [$taskId, payload] or null.
     * Claim ownership belongs to the caller via task_claim (CouchDB+graph).
     */
    public function claimTask(string $queue): ?array {
        $taskId = $this->client->command('LPOP', self::NS . 'queue:' . $queue);
        if ($taskId === null || $taskId === false) { return null; }
        $fields = $this->client->command('HGETALL', self::NS . 'task:' . $taskId) ?: [];
        $payload = null;
        for ($i = 0; $i + 1 < count($fields); $i += 2) {
            if ($fields[$i] === 'payload') { $payload = json_decode($fields[$i + 1], true); }
        }
        return ['id' => $taskId, 'payload' => $payload];
    }

    /** Return a task id to the head of the queue (caller released it). */
    public function releaseToQueue(string $queue, string $taskId): void {
        $this->client->command('LPUSH', self::NS . 'queue:' . $queue, $taskId);
    }

    public function ping(): bool {
        return $this->client->command('PING') === 'PONG';
    }

    /**
     * Acquire an advisory lock. Value is "$ownerId" with TTL.
     * Returns true when acquired, false when held by someone else.
     */
    public function acquireLock(string $name, string $ownerId, int $ttlSeconds): bool {
        $result = $this->client->command('SET', self::NS . 'lock:' . $name, $ownerId, 'NX', 'EX', (string)$ttlSeconds);
        return $result === 'OK';
    }

    /** Release a lock only when the caller owns it. Returns true when released. */
    public function releaseLock(string $name, string $ownerId): bool {
        $result = $this->client->command('EVAL', self::LOCK_RELEASE_LUA, '1', self::NS . 'lock:' . $name, $ownerId);
        return $result === 1;
    }

    /** Inspect a lock: [owner, ttlSeconds] or null when free. */
    public function checkLock(string $name): ?array {
        $owner = $this->client->command('GET', self::NS . 'lock:' . $name);
        if ($owner === null) { return null; }
        $ttl = $this->client->command('PTTL', self::NS . 'lock:' . $name);
        return ['owner' => $owner, 'ttl_ms' => is_int($ttl) ? $ttl : -1];
    }

    /** Full pub/sub channel name for a logical signal channel (namespaced). */
    public static function signalChannel(string $channel): string {
        return self::NS . 'signal:' . $channel;
    }

    public function publishSignal(string $channel, array $signal): void {
        $this->client->command('PUBLISH', self::signalChannel($channel), json_encode($signal));
    }

    /** Register agent presence + heartbeat timestamp. */
    public function heartbeat(string $agentId, array $meta = []): void {
        $this->client->command('SADD', self::NS . 'agents', $agentId);
        $args = [self::NS . 'agent:' . $agentId, 'heartbeat', (string)time()];
        foreach ($meta as $k => $v) { $args[] = (string)$k; $args[] = is_string($v) ? $v : json_encode($v); }
        $this->client->command('HSET', ...$args);
    }

    public function agentHeartbeatTime(string $agentId): ?int {
        $ts = $this->client->command('HGET', self::NS . 'agent:' . $agentId, 'heartbeat');
        return ($ts === null || $ts === false) ? null : (int)$ts;
    }

    /** Full presence record for an agent (heartbeat timestamp + meta), or null. */
    public function agentRecord(string $agentId): ?array {
        $fields = $this->client->command('HGETALL', self::NS . 'agent:' . $agentId) ?: [];
        if (empty($fields)) { return null; }
        $record = [];
        for ($i = 0; $i + 1 < count($fields); $i += 2) { $record[$fields[$i]] = $fields[$i + 1]; }
        return $record;
    }

    /**
     * Read up to $limit inbox messages, leaving the remainder queued.
     * Two step LRANGE+LTRIM: not atomic across concurrent pulls of the same
     * inbox (single consumer per agent in practice).
     */
    public function inboxPull(string $agentId, int $limit): array {
        $key = self::NS . 'inbox:' . $agentId;
        $items = $this->client->command('LRANGE', $key, '0', (string)($limit - 1)) ?: [];
        if (empty($items)) { return []; }
        $this->client->command('LTRIM', $key, (string)count($items), '-1');
        return array_map(fn($j) => json_decode($j, true), $items);
    }

    /** All currently held locks: name => ['owner'=>, 'ttl_ms'=>]. */
    public function listLocks(): array {
        $locks = [];
        $cursor = '0';
        do {
            $result = $this->client->command('SCAN', $cursor, 'MATCH', self::NS . 'lock:*', 'COUNT', '100');
            if (!is_array($result) || count($result) < 2) { break; }
            $cursor = (string)$result[0];
            foreach ((array)$result[1] as $key) {
                $name = substr($key, strlen(self::NS . 'lock:'));
                $held = $this->checkLock($name);
                if ($held !== null) { $locks[$name] = $held; }
            }
        } while ($cursor !== '0');
        return $locks;
    }

    /** Registered agent ids (not liveness-filtered). */
    public function agents(): array {
        return $this->client->command('SMEMBERS', self::NS . 'agents') ?: [];
    }

    public function deregisterAgent(string $agentId): void {
        $this->client->command('SREM', self::NS . 'agents', $agentId);
        $this->client->command('DEL', self::NS . 'agent:' . $agentId);
    }

    /** Queue a message for an agent; returns queue depth after push. */
    public function inboxPush(string $agentId, array $message): int {
        return (int)$this->client->command('RPUSH', self::NS . 'inbox:' . $agentId, json_encode($message));
    }

    /** Drain all pending inbox messages for an agent. */
    public function inboxDrain(string $agentId): array {
        $key = self::NS . 'inbox:' . $agentId;
        $items = $this->client->command('LRANGE', $key, '0', '-1') ?: [];
        if (!empty($items)) { $this->client->command('DEL', $key); }
        return array_map(fn($j) => json_decode($j, true), $items);
    }

    public function inboxDepth(string $agentId): int {
        return (int)$this->client->command('LLEN', self::NS . 'inbox:' . $agentId);
    }

    /** Count of board-visible sectors: pending tasks per known queue. */
    public function queueDepth(string $queue): int {
        return (int)$this->client->command('LLEN', self::NS . 'queue:' . $queue);
    }
}
