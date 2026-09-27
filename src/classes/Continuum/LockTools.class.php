<?php

namespace Continuum;

use EnchiladaMCP\McpTool;
use EnchiladaMCP\ToolResult;
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
        description: 'Acquire a named advisory lock for ttlSeconds (default 300). It blocks nothing; other agents are expected to respect it. Fails if another agent holds it.',
        outputSchema: self::ACQUIRE_SCHEMA
    )]
    public function advisory_lock_acquire(string $name, int $ttlSeconds = 300): ToolResult {
        if ($ttlSeconds < 1) {
            throw new \RuntimeException('ttlSeconds must be >= 1');
        }
        $held = $this->storage->checkLock($name);
        if ($held !== null && $held['owner'] !== CONTINUUM_AGENT) {
            $data = ['name' => $name, 'acquired' => false, 'owner' => $held['owner'], 'ttl_ms' => $held['ttl_ms']];
            return ToolResult::structured(
                "Lock '{$name}' not acquired — held by {$held['owner']} ({$held['ttl_ms']}ms TTL left).", $data);
        }
        if (!$this->storage->acquireLock($name, CONTINUUM_AGENT, $ttlSeconds)) {
            return ToolResult::structured("Lock '{$name}' not acquired (a competing claim won the race).",
                ['name' => $name, 'acquired' => false]);
        }
        $this->storage->appendLog(CONTINUUM_AGENT, 'advisory_lock_acquire', ['lock' => $name, 'ttl' => $ttlSeconds]);
        $data = ['name' => $name, 'acquired' => true, 'owner' => CONTINUUM_AGENT, 'ttl' => $ttlSeconds];
        return ToolResult::structured("Acquired lock '{$name}' ({$ttlSeconds}s TTL).", $data);
    }

    #[McpTool(
        name: 'advisory_lock_release',
        renamedFrom: 'lock_release',
        description: 'Release a named advisory lock you own. Force-releasing another agent\'s lock (e.g. expired or orphaned) needs confirmation: leave confirm null to be prompted, or pass the answer object directly.',
        idempotentHint: true,
        outputSchema: self::RELEASE_SCHEMA
    )]
    public function advisory_lock_release(string $name, ?array $confirm = null): ToolResult {
        if ($this->storage->releaseLock($name, CONTINUUM_AGENT)) {
            $this->storage->appendLog(CONTINUUM_AGENT, 'advisory_lock_release', ['lock' => $name]);
            return ToolResult::structured("Released lock '{$name}'.", ['name' => $name, 'released' => true]);
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
                $data = ['name' => $name, 'released' => false, 'owner' => $held['owner'], 'declined' => true];
                return ToolResult::structured("Force-release of '{$name}' declined — still held by {$held['owner']}.", $data);
            }
            $released = $this->storage->forceReleaseLock($name);
            if ($released) {
                $this->storage->appendLog(CONTINUUM_AGENT, 'advisory_lock_force_release', ['lock' => $name, 'from' => $held['owner']]);
            }
            $data = ['name' => $name, 'released' => $released, 'forced' => true];
            $text = $released
                ? "Force-released lock '{$name}' (was held by {$held['owner']})."
                : "Lock '{$name}' vanished before the force-release landed.";
            return ToolResult::structured($text, $data);
        }
        return ToolResult::structured("Lock '{$name}' is free — nothing to release.", ['name' => $name, 'released' => false]);
    }

    private const ACQUIRE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'acquired' => ['type' => 'boolean'],
            'owner' => ['type' => 'string'],
            'ttl_ms' => ['type' => 'integer'],
            'ttl' => ['type' => 'integer'],
        ],
        'required' => ['name', 'acquired'],
    ];

    private const RELEASE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'released' => ['type' => 'boolean'],
            'owner' => ['type' => 'string'],
            'declined' => ['type' => 'boolean'],
            'forced' => ['type' => 'boolean'],
        ],
        'required' => ['name', 'released'],
    ];

    #[McpTool(
        name: 'advisory_lock_check',
        renamedFrom: 'lock_check',
        description: 'Inspect a named advisory lock: current owner and TTL, or free.',
        readOnlyHint: true,
        outputSchema: self::CHECK_SCHEMA
    )]
    public function advisory_lock_check(string $name): ToolResult {
        $held = $this->storage->checkLock($name);
        if ($held === null) {
            return ToolResult::structured("Lock '{$name}' is free.", ['name' => $name, 'free' => true]);
        }
        $mine = $held['owner'] === CONTINUUM_AGENT;
        $data = [
            'name' => $name,
            'free' => false,
            'owner' => $held['owner'],
            'ttl_ms' => $held['ttl_ms'],
            'mine' => $mine,
        ];
        return ToolResult::structured(
            "Lock '{$name}' is held by {$held['owner']} ({$held['ttl_ms']}ms TTL left)" . ($mine ? ' — held by you' : '') . '.',
            $data
        );
    }

    private const CHECK_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'free' => ['type' => 'boolean'],
            'owner' => ['type' => 'string'],
            'ttl_ms' => ['type' => 'integer'],
            'mine' => ['type' => 'boolean'],
        ],
        'required' => ['name', 'free'],
    ];
}
