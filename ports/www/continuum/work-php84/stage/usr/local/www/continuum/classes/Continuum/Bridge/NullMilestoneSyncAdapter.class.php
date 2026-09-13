<?php

namespace Continuum\Bridge;

/** Default adapter: milestones stay local (no external tracker wired). */
class NullMilestoneSyncAdapter implements MilestoneSyncAdapterInterface {
    public function syncMilestone(string $taskId, string $milestone, array $data): void {
        // Intentionally a no-op.
    }
}
