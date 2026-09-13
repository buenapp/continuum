<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use Continuum\Storage\ContinuumStorage;

/**
 * Advisory TTL locks (ValKey SET NX EX + compare-and-delete release).
 *
 * Locks expire on their own, so a crashed agent cannot deadlock the
 * board. A release only succeeds for the lock owner. Successful
 * acquires and releases are written to the event log.
 */
class LockTools {

    public function __construct(private ContinuumStorage $storage) {}

    #[McpTool(
        name: 'advisory_lock_acquire',
        renamedFrom: 'lock_acquire',
        description: 'Acquire an advisory named lock with a TTL (seconds). Advisory means cooperation-based: holders cannot block others from touching the resource — it is a claim other agents are expected to respect. Fails cleanly when another agent holds it.'
    )]
    public function advisory_lock_acquire(string $name, int $ttlSeconds = 300): array {
        if ($ttlSeconds < 1) {
            throw new \RuntimeException('ttlSeconds must be >= 1');
        }
        $held = $this->storage->checkLock($name);
        if ($held !== null && $held['owner'] !== CONTINUUM_AGENT) {
            return ['name' => $name, 'acquired' => false, 'owner' => $held['owner'], 'ttl_ms' => $held['ttl_ms']];
        }
        if (!$this->storage->acquireLock($name, CONTINUUM_AGENT, $ttlSeconds)) {
            return ['name' => $name, 'acquired' => false];
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'advisory_lock_acquire', ['lock' => $name, 'ttl' => $ttlSeconds]);
        return ['name' => $name, 'acquired' => true, 'owner' => CONTINUUM_AGENT, 'ttl' => $ttlSeconds];
    }

    #[McpTool(
        name: 'advisory_lock_release',
        renamedFrom: 'lock_release',
        description: 'Release a named advisory lock. Only the owner can release; a non-owner release fails.',
        idempotentHint: true
    )]
    public function advisory_lock_release(string $name): array {
        $released = $this->storage->releaseLock($name, CONTINUUM_AGENT);
        if ($released) {
            $this->storage->appendLog(CONTINUUM_AGENT, 'advisory_lock_release', ['lock' => $name]);
        }
        return ['name' => $name, 'released' => $released];
    }

    #[McpTool(
        name: 'advisory_lock_check',
        renamedFrom: 'lock_check',
        description: 'Inspect a named advisory lock: current owner and TTL, or free.',
        readOnlyHint: true
    )]
    public function advisory_lock_check(string $name): array {
        $held = $this->storage->checkLock($name);
        if ($held === null) {
            return ['name' => $name, 'free' => true];
        }
        return [
            'name' => $name,
            'free' => false,
            'owner' => $held['owner'],
            'ttl_ms' => $held['ttl_ms'],
            'mine' => $held['owner'] === CONTINUUM_AGENT,
        ];
    }
}
