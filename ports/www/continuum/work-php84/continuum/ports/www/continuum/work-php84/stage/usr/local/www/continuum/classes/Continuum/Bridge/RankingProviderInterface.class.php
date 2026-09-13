<?php

namespace Continuum\Bridge;

/**
 * Ranking seam for context_pack. Items are assoc arrays that must carry a
 * 'text' key; rank() returns the items reordered, each augmented with a
 * '_score'. Implementations must tolerate an arbitrary item count.
 */
interface RankingProviderInterface {
    public function rank(array $items, string $query): array;
}
