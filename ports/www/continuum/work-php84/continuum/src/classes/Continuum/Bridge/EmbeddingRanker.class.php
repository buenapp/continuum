<?php

namespace Continuum\Bridge;

/** Semantic ranking via embedding cosine similarity. */
class EmbeddingRanker implements RankingProviderInterface {

    public function __construct(
        private EmbeddingProvider $provider,
        private string $model,
        private string $apiKey = '',
    ) {}

    public function rank(array $items, string $query): array {
        if (empty($items)) { return $items; }
        $texts = array_map(fn($i) => $i['text'] ?? '', $items);
        $vectors = $this->provider->embed(array_merge([$query], $texts), $this->model, $this->apiKey);
        $qv = array_shift($vectors);
        $qnorm = sqrt(array_sum(array_map(fn($x) => $x * $x, $qv))) ?: 1.0;
        foreach ($items as $i => $item) {
            $v = $vectors[$i] ?? [];
            $dot = 0.0; $vnorm = 0.0;
            foreach ($v as $j => $x) { $dot += $x * ($qv[$j] ?? 0); $vnorm += $x * $x; }
            $items[$i]['_score'] = $vnorm > 0 ? $dot / (sqrt($vnorm) * $qnorm) : 0.0;
        }
        usort($items, fn($a, $b) => $b['_score'] <=> $a['_score']);
        return $items;
    }
}
