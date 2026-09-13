<?php

namespace Continuum;

use EnchiladaMCP\McpResource;
use Continuum\Storage\ContinuumStorage;

/**
 * Advisory lock resources: the held-lock set and per-lock state.
 *
 * Locks carry no modification timestamp (TTL only), so these reads
 * advertise no lastModified.
 */
class LockResources {

    public function __construct(private ContinuumStorage $storage) {}

    /**
     * All currently held advisory locks.
     */
    #[McpResource(
        uriTemplate: 'continuum://locks',
        description: 'All currently held advisory locks with owner and remaining TTL.',
        annotations: ['audience' => ['assistant']]
    )]
    public function lock_list(): array {
        $locks = [];
        foreach ($this->storage->listLocks() as $name => $held) {
            $locks[] = [
                'name' => $name,
                'owner' => $held['owner'],
                'ttl_ms' => $held['ttl_ms'],
                'mine' => $held['owner'] === CONTINUUM_AGENT,
            ];
        }
        return ['count' => count($locks), 'locks' => $locks];
    }

    /**
     * State of one named lock.
     */
    #[McpResource(
        uriTemplate: 'continuum://locks/{name}',
        description: 'Inspect one advisory lock: owner and TTL, or free.',
        annotations: ['audience' => ['assistant']]
    )]
    public function lock_card(string $name): array {
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
