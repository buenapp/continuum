<?php

namespace Continuum\Bridge;

/**
 * Milestone sync adapter (Option C hybrid): blackboard tasks move freely;
 * only milestones (started / blocked / resolved) propagate outward to a
 * canonical work tracker. This interface is the anti-corruption seam —
 * no tracker implementation (Phorge/Conduit or otherwise) is a hard
 * dependency.
 */
interface MilestoneSyncAdapterInterface {

    public const MILESTONES = ['started', 'blocked', 'resolved'];

    /**
     * Propagate a milestone. Implementations must be fast and
     * non-authoritative: failures throw and are reported, but the local
     * task transition has already committed.
     */
    public function syncMilestone(string $taskId, string $milestone, array $data): void;
}
