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
 *   session:{agent}:{sid}    HASH  session-scoped presence (TTL-refreshed)
 *   agent-sessions:{agent}   SET   session ids seen for an agent
 *   inbox:{agentId}          LIST  inbound messages (JSON; may carry
 *                                   session/task targeting and lease markers)
 *   signal:{channel}         PUBSUB channels (no persistence)
 */
class ValKeyStore {

    private const NS = 'continuum:';
    private const LOCK_RELEASE_LUA = 'if redis.call("get",KEYS[1]) == ARGV[1] then return redis.call("del",KEYS[1]) else return 0 end';

    /** Stale session cards vanish after this many idle seconds. */
    private const SESSION_TTL = 14400;

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

    /** Release a lock regardless of owner. Returns true when one was held. */
    public function forceReleaseLock(string $name): bool {
        return (int)$this->client->command('DEL', self::NS . 'lock:' . $name) > 0;
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

    /**
     * Session-scoped presence record. One API key can back several
     * concurrent sessions; each gets its own hash so concurrent agents
     * sharing an identity do not clobber each other's working-on state.
     * Records expire when unrefreshed; membership prunes on read.
     */
    public function sessionHeartbeat(string $agentId, string $sessionId, array $meta = []): void {
        $key = self::NS . 'session:' . $agentId . ':' . $sessionId;
        $args = [$key, 'agent', $agentId, 'session', $sessionId, 'heartbeat', (string)time()];
        foreach ($meta as $k => $v) { $args[] = (string)$k; $args[] = is_string($v) ? $v : json_encode($v); }
        $this->client->command('HSET', ...$args);
        $this->client->command('EXPIRE', $key, (string)self::SESSION_TTL);
        $this->client->command('SADD', self::NS . 'agent-sessions:' . $agentId, $sessionId);
    }

    /** Live session records for an agent: sessionId => record. */
    public function sessionRecords(string $agentId): array {
        $set = self::NS . 'agent-sessions:' . $agentId;
        $out = [];
        foreach ($this->client->command('SMEMBERS', $set) ?: [] as $sid) {
            $fields = $this->client->command('HGETALL', self::NS . 'session:' . $agentId . ':' . $sid) ?: [];
            if (count($fields) < 2) {
                // hash expired since membership was recorded; drop the ghost
                $this->client->command('SREM', $set, $sid);
                continue;
            }
            $record = [];
            for ($i = 0; $i + 1 < count($fields); $i += 2) { $record[$fields[$i]] = $fields[$i + 1]; }
            $out[$sid] = $record;
        }
        return $out;
    }

    /** Newest heartbeat across the agent record and its live sessions. */
    public function lastSeen(string $agentId): ?int {
        $ts = $this->agentHeartbeatTime($agentId);
        foreach ($this->sessionRecords($agentId) as $rec) {
            $sessionTs = isset($rec['heartbeat']) ? (int)$rec['heartbeat'] : null;
            if ($sessionTs !== null) { $ts = max($ts ?? 0, $sessionTs); }
        }
        return $ts;
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
     * Destructive legacy pull: take up to $limit untargeted messages,
     * oldest first, and remove them. Messages carrying a session target,
     * a live lease, or a passed expiry are left alone (expired ones are
     * dropped from the list). Targeted messages need message_lease.
     */
    public function inboxPull(string $agentId, int $limit): array {
        $now = time();
        $pulled = $this->inboxRewrite($agentId, function (array $entries) use ($limit, $now) {
            $kept = [];
            $taken = [];
            foreach ($entries as $e) {
                if (self::isExpired($e, $now)) { continue; }
                if (count($taken) < $limit && !self::leaseActive($e, $now) && empty($e['session'])) {
                    $taken[] = self::stripLease($e);
                    continue;
                }
                $kept[] = $e;
            }
            return [$kept, $taken];
        });
        if ($pulled === null) {
            throw new \RuntimeException("inbox for {$agentId} changed concurrently; retry the pull");
        }
        return $pulled;
    }

    /**
     * Lease up to $limit messages eligible for $session (untargeted plus
     * ones addressed to it), oldest first. Leased messages are marked
     * in place until $ttlSeconds pass; an expired lease makes a message
     * eligible again. Returns ['id', 'expires_at', 'messages'].
     */
    public function inboxLease(string $agentId, ?string $session, int $limit, int $ttlSeconds, int $now): array {
        $leaseId = 'L-' . strtoupper(bin2hex(random_bytes(4)));
        $until = $now + $ttlSeconds;
        $messages = $this->inboxRewrite($agentId, function (array $entries) use ($session, $limit, $now, $until, $leaseId) {
            $kept = [];
            $taken = [];
            foreach ($entries as $e) {
                if (self::isExpired($e, $now)) { continue; }
                if (self::leaseActive($e, $now)) { $kept[] = $e; continue; }
                $e = self::stripLease($e); // a dead lease returns the message
                $eligible = empty($e['session']) || ($session !== null && ($e['session'] ?? null) === $session);
                if ($eligible && count($taken) < $limit) {
                    $e['lease'] = $leaseId;
                    $e['lease_until'] = $until;
                    $taken[] = $e;
                }
                $kept[] = $e;
            }
            return [$kept, $taken];
        });
        if ($messages === null) {
            throw new \RuntimeException("inbox for {$agentId} changed concurrently; retry the lease");
        }
        return ['id' => $leaseId, 'expires_at' => gmdate('c', $until), 'messages' => $messages];
    }

    /**
     * Acknowledge (delete) messages by id. Returns the ids actually
     * found and removed; unknown or already-gone ids are simply absent.
     */
    public function inboxAck(string $agentId, array $ids, int $now): array {
        $wanted = array_fill_keys($ids, true);
        $acked = $this->inboxRewrite($agentId, function (array $entries) use ($wanted, $now) {
            $kept = [];
            $removed = [];
            foreach ($entries as $e) {
                if (isset($wanted[$e['id'] ?? ''])) { $removed[] = $e['id']; continue; }
                if (self::isExpired($e, $now)) { continue; }
                $kept[] = $e;
            }
            return [$kept, $removed];
        });
        if ($acked === null) {
            throw new \RuntimeException("inbox for {$agentId} changed concurrently; retry the ack");
        }
        return $acked;
    }

    /**
     * Optimistic read-modify-write of the whole inbox list.
     *
     * $transform receives the decoded entries and returns [kept, result].
     * The list is replaced by `kept` inside WATCH/MULTI/EXEC, so a
     * concurrent writer loses nothing: EXEC then reports the race and we
     * re-read once before giving up with null. Unchanged lists (no
     * reaping, nothing selected) skip the write entirely.
     */
    private function inboxRewrite(string $agentId, callable $transform): ?array {
        $key = self::NS . 'inbox:' . $agentId;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->client->command('WATCH', $key);
            $raw = $this->client->command('LRANGE', $key, '0', '-1') ?: [];
            $entries = array_map(fn($j) => json_decode($j, true) ?? [], $raw);
            [$kept, $result] = $transform($entries);
            if ($kept === $entries) {
                $this->client->command('UNWATCH');
                return $result;
            }
            $this->client->command('MULTI');
            $this->client->command('DEL', $key);
            if ($kept !== []) {
                $this->client->command('RPUSH', $key, ...array_map(fn($e) => json_encode($e), $kept));
            }
            if ($this->client->command('EXEC') !== null) {
                return $result;
            }
        }
        return null;
    }

    /** A message outlives its welcome once its ISO-8601 expiry passes. */
    private static function isExpired(array $entry, int $now): bool {
        $expires = $entry['expires'] ?? null;
        if ($expires === null) { return false; }
        $ts = strtotime((string)$expires);
        return $ts !== false && $ts <= $now;
    }

    /** Live lease marker set by inboxLease. */
    private static function leaseActive(array $entry, int $now): bool {
        return !empty($entry['lease']) && ($entry['lease_until'] ?? 0) > $now;
    }

    private static function stripLease(array $entry): array {
        unset($entry['lease'], $entry['lease_until']);
        return $entry;
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
        $set = self::NS . 'agent-sessions:' . $agentId;
        foreach ($this->client->command('SMEMBERS', $set) ?: [] as $sid) {
            $this->client->command('DEL', self::NS . 'session:' . $agentId . ':' . $sid);
        }
        $this->client->command('DEL', $set);
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
