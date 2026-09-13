<?php

namespace Continuum\Bridge;

/** Token-overlap (cosine on token sets) ranking; zero dependencies. */
class LexicalRanker implements RankingProviderInterface {

    public function rank(array $items, string $query): array {
        $queryTokens = $this->tokens($query);
        foreach ($items as $i => $item) {
            $itemTokens = $this->tokens($item['text'] ?? '');
            $overlap = array_intersect($queryTokens, $itemTokens);
            $denom = sqrt(count($queryTokens) * max(1, count($itemTokens)));
            $items[$i]['_score'] = $denom > 0 ? count($overlap) / $denom : 0.0;
            $items[$i]['_pos'] = $i; // stable tiebreak on original order
        }
        usort($items, fn($a, $b) => ($b['_score'] <=> $a['_score']) ?: ($a['_pos'] <=> $b['_pos']));
        foreach ($items as &$item) { unset($item['_pos']); }
        return $items;
    }

    /** Lowercased word tokens, stopwords removed. */
    private function tokens(string $text): array {
        static $stop = ['the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'on', 'for', 'with', 'is', 'are', 'be', 'by', 'at'];
        preg_match_all('/[a-z0-9_]{2,}/', strtolower($text), $m);
        return array_values(array_unique(array_diff($m[0], $stop)));
    }
}
