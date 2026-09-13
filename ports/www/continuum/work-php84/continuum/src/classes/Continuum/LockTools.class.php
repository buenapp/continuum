<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ElicitationRequired;
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
        description: 'Release a named advisory lock. Only the owner can release; a non-owner release fails unless force-released after confirmation: leave `confirm` null to be prompted (MRTR elicitation), or pass the answer object directly. An expired or orphaned lock is a legitimate force-release target.',
        idempotentHint: true
    )]
    public function advisory_lock_release(string $name, ?array $confirm = null): array {
        if ($this->storage->releaseLock($name, CONTINUUM_AGENT)) {
            $this->storage->appendLog(CONTINUUM_AGENT, 'advisory_lock_release', ['lock' => $name]);
            return ['name' => $name, 'released' => true];
        }
        $held = $this->storage->checkLock($name);
        if ($held !== null && $held['owner'] !== CONTINUUM_AGENT) {
            $answer = ElicitationRequired::answer($confirm);
            if ($answer === null) {
                throw new ElicitationRequired('confirm', "Lock {$name} is held by {$held['owner']} ({$held['ttl_ms']}ms TTL left). Force-release it?", [
                    'type' => 'object',
                    'properties' => ['approve' => ['type' => 'boolean', 'title' => 'Force-release the lock']],
                    'required' => ['approve'],
                ]);
            }
            if (!$answer) {
                return ['name' => $name, 'released' => false, 'owner' => $held['owner'], 'declined' => true];
            }
            $released = $this->storage->forceReleaseLock($name);
            if ($released) {
                $this->storage->appendLog(CONTINUUM_AGENT, 'advisory_lock_force_release', ['lock' => $name, 'from' => $held['owner']]);
            }
            return ['name' => $name, 'released' => $released, 'forced' => true];
        }
        return ['name' => $name, 'released' => false];
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
